<?php

declare(strict_types=1);

namespace App\Domains\Intake\Actions;

use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Services\PdokAddressService;
use App\Enums\ContributionMode;
use App\Enums\IntakeSource;
use App\Enums\IntakeStatus;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

final class StartEmbedIntake
{
    public function __construct(
        private readonly CreateIntake $createIntake,
        private readonly EnrichIntakeAddress $enrichIntakeAddress,
        private readonly SendCustomerIntakeLink $sendCustomerIntakeLink,
        private readonly PdokAddressService $pdokAddressService,
        private readonly SaveIntakeAnswer $saveIntakeAnswer,
    ) {}

    /**
     * Preview a resolved postal address for the embed confirmation step (no intake create).
     *
     * @param  array{
     *     address_postal_code: string,
     *     address_house_number: int|string,
     *     address_house_number_addition?: string|null,
     * }  $data
     * @return array{lookup_id: string, address_line: string, city: string, summary: string}
     */
    public function resolveAddressPreview(array $data): array
    {
        [$postalCode, $houseNumber, $addition] = $this->normalizePostalParts($data);
        $resolved = $this->resolvePostalAddress($postalCode, $houseNumber, $addition);

        return [
            ...$resolved,
            'summary' => $this->formatAddressSummary(
                $resolved['address_line'],
                $postalCode,
                $resolved['city'],
            ),
        ];
    }

    /**
     * @param  array{
     *     customer_name: string,
     *     customer_email: string,
     *     customer_phone?: string|null,
     *     address_postal_code: string,
     *     address_house_number: int|string,
     *     address_house_number_addition?: string|null,
     * }  $data
     * @return array{intake: Intake, created: bool}
     */
    public function handle(Company $company, array $data, ?string $refererHost = null): array
    {
        $creator = $this->oldestCompanyUser($company);
        $email = strtolower(trim((string) $data['customer_email']));

        $existing = $this->findReusableOpenIntake($company, $email);

        if ($existing !== null) {
            // Dedup: mail opnieuw, géén redirect naar /o/{token} (geen token-lek).
            // Telt niet mee voor het dagplafond.
            $this->sendCustomerIntakeLink->handle($existing, $creator);

            return ['intake' => $existing, 'created' => false];
        }

        $this->assertWithinDailyLimit($company);

        [$postalCode, $houseNumber, $addition] = $this->normalizePostalParts($data);

        // Resolve street/city via PDOK before create so the wizard never shows the
        // temporary "Onbekend" placeholder, and unknown addresses get a clear 422.
        $resolved = $this->resolvePostalAddress($postalCode, $houseNumber, $addition);

        $activityProperties = [];
        if (is_string($refererHost) && $refererHost !== '') {
            $activityProperties['embed_referer_host'] = $refererHost;
        }

        $intake = DB::transaction(function () use (
            $creator,
            $data,
            $email,
            $postalCode,
            $houseNumber,
            $addition,
            $resolved,
            $activityProperties,
        ): Intake {
            return $this->createIntake->handle($creator, [
                'customer_name' => $data['customer_name'],
                'customer_email' => $email,
                'customer_phone' => $data['customer_phone'] ?? null,
                'address_line' => $resolved['address_line'],
                'address_postal_code' => $postalCode,
                'address_house_number' => $houseNumber,
                'address_house_number_addition' => $addition,
                'address_city' => $resolved['city'],
                'workflow_mode' => ContributionMode::Customer,
                'source' => IntakeSource::Embed,
                'activity_actor_type' => 'embed',
                'activity_properties' => $activityProperties,
            ]);
        });

        $this->enrichIntakeAddress->handle($intake, $resolved['lookup_id']);
        $fresh = $intake->fresh() ?? $intake;

        // AVG: het embed-formulier vraagt expliciete toestemming (niet vooraf aangevinkt).
        // Sla die op als echt klantantwoord (geen prefill), zodat de wizard-stap
        // privacy_consent niet opnieuw verschijnt en de stappenteller niet oploopt.
        $this->recordEmbedPrivacyConsent($fresh);

        // Backup-mail; geen AI-job (BL-152). Fail-soft zit in SendCustomerIntakeLink.
        $this->sendCustomerIntakeLink->handle($fresh, $creator);

        return ['intake' => $fresh->fresh() ?? $fresh, 'created' => true];
    }

    /**
     * Persist the embed form checkbox as an accepted privacy_consent answer.
     * prefill_source stays null so MustAcceptQuestions counts it as real acceptance.
     */
    private function recordEmbedPrivacyConsent(Intake $intake): void
    {
        $this->saveIntakeAnswer->handle(
            $intake,
            'privacy_consent',
            null,
            ['bool' => true],
            advanceCustomerProgress: false,
        );
    }

    /**
     * @param  array{
     *     address_postal_code: string,
     *     address_house_number: int|string,
     *     address_house_number_addition?: string|null,
     * }  $data
     * @return array{0: string, 1: int, 2: string|null}
     */
    private function normalizePostalParts(array $data): array
    {
        $postalCode = strtoupper((string) preg_replace('/\s+/', '', trim((string) $data['address_postal_code'])));
        $houseNumber = (int) $data['address_house_number'];
        $addition = strtoupper(trim((string) ($data['address_house_number_addition'] ?? '')));
        $addition = $addition === '' ? null : $addition;

        return [$postalCode, $houseNumber, $addition];
    }

    private function formatAddressSummary(string $addressLine, string $postalCode, string $city): string
    {
        $displayPostal = strlen($postalCode) === 6
            ? substr($postalCode, 0, 4).' '.substr($postalCode, 4, 2)
            : $postalCode;

        return trim($addressLine).', '.$displayPostal.' '.trim($city);
    }

    /**
     * @return array{lookup_id: string, address_line: string, city: string}
     */
    private function resolvePostalAddress(string $postalCode, int $houseNumber, ?string $addition): array
    {
        try {
            $suggestions = $this->pdokAddressService->suggestForPostalAddress(
                $postalCode,
                $houseNumber,
                $addition,
            );
        } catch (Throwable $exception) {
            Log::warning('Embed PDOK address lookup failed.', [
                'exception' => $exception::class,
            ]);

            throw ValidationException::withMessages([
                'address_postal_code' => 'We konden je adres nu niet controleren. Probeer het zo opnieuw.',
            ]);
        }

        if ($suggestions === []) {
            throw ValidationException::withMessages([
                'address_house_number' => 'We konden dit adres niet vinden. Controleer postcode en huisnummer.',
            ]);
        }

        $match = $this->preferExactAdditionMatch($suggestions, $addition);

        $addressLine = trim($match['address_line']);
        $city = trim($match['city']);
        $lookupId = $match['id'];

        if ($addressLine === '' || $city === '' || $lookupId === '' || ! str_starts_with($lookupId, 'adr-')) {
            throw ValidationException::withMessages([
                'address_house_number' => 'We konden dit adres niet vinden. Controleer postcode en huisnummer.',
            ]);
        }

        return [
            'lookup_id' => $lookupId,
            'address_line' => $addressLine,
            'city' => $city,
        ];
    }

    /**
     * @param  list<array{id: string, label: string, address_line: string, postal_code: string, house_number: int, house_number_addition: string|null, city: string}>  $suggestions
     * @return array{id: string, label: string, address_line: string, postal_code: string, house_number: int, house_number_addition: string|null, city: string}
     */
    private function preferExactAdditionMatch(array $suggestions, ?string $addition): array
    {
        if ($addition === null) {
            foreach ($suggestions as $suggestion) {
                if (($suggestion['house_number_addition'] ?? null) === null) {
                    return $suggestion;
                }
            }
        }

        return $suggestions[0];
    }

    private function oldestCompanyUser(Company $company): User
    {
        $user = User::query()
            ->where('company_id', $company->id)
            ->orderBy('id')
            ->first();

        if ($user === null) {
            throw new HttpException(404);
        }

        return $user;
    }

    private function findReusableOpenIntake(Company $company, string $normalizedEmail): ?Intake
    {
        $openStatuses = array_map(
            static fn (IntakeStatus $status): string => $status->value,
            array_values(array_filter(
                IntakeStatus::cases(),
                static fn (IntakeStatus $status): bool => $status->isCustomerAccessible(),
            )),
        );

        $candidates = Intake::query()
            ->where('company_id', $company->id)
            ->whereIn('status', $openStatuses)
            ->whereRaw('lower(customer_email) = ?', [$normalizedEmail])
            ->orderByDesc('id')
            ->get();

        foreach ($candidates as $candidate) {
            if ($candidate->isTokenValid()) {
                return $candidate;
            }
        }

        return null;
    }

    private function assertWithinDailyLimit(Company $company): void
    {
        $limit = max(1, (int) $company->embed_daily_limit);
        $createdToday = Intake::query()
            ->where('company_id', $company->id)
            ->where('source', IntakeSource::Embed)
            ->whereDate('created_at', now()->toDateString())
            ->count();

        if ($createdToday >= $limit) {
            throw new HttpException(
                429,
                'Het maximum aantal opnames via dit formulier voor vandaag is bereikt. Probeer het morgen opnieuw of neem contact op met je installateur.',
            );
        }
    }
}
