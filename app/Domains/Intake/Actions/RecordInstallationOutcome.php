<?php

declare(strict_types=1);

namespace App\Domains\Intake\Actions;

use App\Domains\Intake\Models\AircoInstallationOption;
use App\Domains\Intake\Models\InstallationOutcome;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeActivityEvent;
use App\Enums\InstallationProposalDelta;
use App\Enums\InstallationSiteVisitReason;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class RecordInstallationOutcome
{
    /**
     * @param  array{
     *     result: string,
     *     active_installer_minutes?: int|null,
     *     customer_minutes?: int|null,
     *     site_visit_occurred?: bool,
     *     site_visit_reasons?: list<string>,
     *     quote_type?: string|null,
     *     installation_surprise?: string|null,
     *     surprise_notes?: string|null,
     *     selected_installation_option_id?: int|null,
     *     proposal_assessed?: bool,
     *     proposal_delta_codes?: list<string>,
     *     installed_at?: string|null
     * }  $data
     */
    public function handle(Intake $intake, User $installer, array $data): InstallationOutcome
    {
        if ($installer->company_id !== $intake->company_id) {
            throw ValidationException::withMessages([
                'outcome' => 'Deze uitkomst hoort niet bij uw installatiebedrijf.',
            ]);
        }

        $optionId = $data['selected_installation_option_id'] ?? null;
        $result = (string) $data['result'];
        $siteVisitOccurred = $result === 'site_visit'
            || (bool) ($data['site_visit_occurred'] ?? false);
        $quoteType = match ($result) {
            'remote_quote' => 'remote',
            'estimate' => 'estimate',
            'installed' => $siteVisitOccurred ? 'after_site_visit' : 'remote',
            default => $data['quote_type'] ?? null,
        };
        $siteVisitReasons = array_values(array_unique($data['site_visit_reasons'] ?? []));
        $proposalAssessed = (bool) ($data['proposal_assessed'] ?? false);
        $proposalDeltaCodes = array_values(array_unique($data['proposal_delta_codes'] ?? []));
        $installationSurprise = $data['installation_surprise'] ?? null;

        if (! in_array($result, ['remote_quote', 'estimate', 'site_visit', 'rejected', 'installed'], true)) {
            throw ValidationException::withMessages([
                'result' => 'Kies een geldige uitkomst van de opname.',
            ]);
        }

        foreach (['active_installer_minutes', 'customer_minutes'] as $minutesKey) {
            $minutes = $data[$minutesKey] ?? null;

            if ($minutes !== null && ($minutes < 0 || $minutes > 10000)) {
                throw ValidationException::withMessages([
                    $minutesKey => 'Leg de tijd vast als een geldig aantal minuten tussen 0 en 10.000.',
                ]);
            }

            $data[$minutesKey] = $minutes;
        }

        if ($quoteType !== null && ! in_array($quoteType, ['remote', 'estimate', 'after_site_visit'], true)) {
            throw ValidationException::withMessages([
                'quote_type' => 'Kies een geldig offertetype.',
            ]);
        }

        if ($installationSurprise !== null
            && ! in_array($installationSurprise, ['none', 'minor', 'major'], true)) {
            throw ValidationException::withMessages([
                'installation_surprise' => 'Kies een geldige montage-uitkomst.',
            ]);
        }

        if (! $siteVisitOccurred && $siteVisitReasons !== []) {
            throw ValidationException::withMessages([
                'site_visit_reasons' => 'Markeer eerst dat een locatiebezoek is uitgevoerd of nodig was.',
            ]);
        }

        if (! $proposalAssessed && $proposalDeltaCodes !== []) {
            throw ValidationException::withMessages([
                'proposal_delta_codes' => 'Markeer eerst dat het voorstel met de definitieve keuze is vergeleken.',
            ]);
        }

        if (count($siteVisitReasons) > 3) {
            throw ValidationException::withMessages([
                'site_visit_reasons' => 'Kies maximaal drie hoofdredenen voor het locatiebezoek.',
            ]);
        }

        if (count($proposalDeltaCodes) > count(InstallationProposalDelta::cases())) {
            throw ValidationException::withMessages([
                'proposal_delta_codes' => 'Er zijn te veel voorstelafwijkingen geselecteerd.',
            ]);
        }

        if ($siteVisitOccurred && $siteVisitReasons === []) {
            throw ValidationException::withMessages([
                'site_visit_reasons' => 'Kies minimaal één reden voor het locatiebezoek.',
            ]);
        }

        if (collect($siteVisitReasons)->contains(
            static fn (string $reason): bool => InstallationSiteVisitReason::tryFrom($reason) === null,
        )) {
            throw ValidationException::withMessages([
                'site_visit_reasons' => 'Een reden voor het locatiebezoek is ongeldig.',
            ]);
        }

        if (collect($proposalDeltaCodes)->contains(
            static fn (string $delta): bool => InstallationProposalDelta::tryFrom($delta) === null,
        )) {
            throw ValidationException::withMessages([
                'proposal_delta_codes' => 'Een afwijking van het voorstel is ongeldig.',
            ]);
        }

        if ($siteVisitOccurred && $quoteType === 'remote') {
            throw ValidationException::withMessages([
                'site_visit_occurred' => 'Een offerte kan niet tegelijk als volledig op afstand én na een locatiebezoek worden vastgelegd.',
            ]);
        }

        if (! $siteVisitOccurred && $quoteType === 'after_site_visit') {
            throw ValidationException::withMessages([
                'quote_type' => 'Markeer het locatiebezoek wanneer de offerte daarna is opgesteld.',
            ]);
        }

        if ($optionId !== null && ! AircoInstallationOption::query()
            ->where('intake_id', $intake->id)
            ->whereKey($optionId)
            ->exists()) {
            throw ValidationException::withMessages([
                'selected_installation_option_id' => 'De gekozen installatieoptie hoort niet bij deze opname.',
            ]);
        }

        return DB::transaction(function () use (
            $intake,
            $installer,
            $data,
            $optionId,
            $result,
            $siteVisitOccurred,
            $siteVisitReasons,
            $quoteType,
            $proposalAssessed,
            $proposalDeltaCodes,
        ): InstallationOutcome {
            $installationSurprise = $result === 'installed'
                ? ($data['installation_surprise'] ?? null)
                : null;
            $outcome = InstallationOutcome::query()->updateOrCreate(
                ['intake_id' => $intake->id],
                [
                    'company_id' => $intake->company_id,
                    'recorded_by' => $installer->id,
                    'selected_installation_option_id' => $optionId,
                    'result' => $result,
                    'active_installer_minutes' => $data['active_installer_minutes'] ?? null,
                    'customer_minutes' => $data['customer_minutes'] ?? null,
                    'site_visit_occurred' => $siteVisitOccurred,
                    'site_visit_reasons' => $siteVisitOccurred ? $siteVisitReasons : null,
                    'quote_type' => $quoteType,
                    'installation_surprise' => $installationSurprise,
                    'surprise_notes' => $installationSurprise === null ? null : ($data['surprise_notes'] ?? null),
                    'proposal_delta' => $proposalAssessed
                        ? ['codes' => $proposalDeltaCodes]
                        : null,
                    'installed_at' => $result === 'installed'
                        ? ($data['installed_at'] ?? now())
                        : null,
                ],
            );

            IntakeActivityEvent::query()->create([
                'intake_id' => $intake->id,
                'actor_type' => 'user',
                'actor_id' => $installer->id,
                'event' => 'installation_outcome_recorded',
                'properties' => [
                    'result' => $outcome->result,
                    'site_visit_occurred' => $outcome->site_visit_occurred,
                    'site_visit_reasons' => $outcome->site_visit_reasons,
                    'installation_surprise' => $outcome->installation_surprise,
                    'proposal_delta_codes' => data_get($outcome->proposal_delta, 'codes'),
                ],
                'created_at' => now(),
            ]);

            return $outcome;
        }, 3);
    }
}
