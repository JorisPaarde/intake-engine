<?php

declare(strict_types=1);

namespace App\Domains\Intake\Services;

use App\Domains\Intake\Actions\CreateCustomerContributionRequest;
use App\Domains\Intake\Actions\SendCustomerFollowUpRequest;
use App\Domains\Intake\Models\AircoInstallationOption;
use App\Domains\Intake\Models\ContributionTask;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeActivityEvent;
use App\Domains\Intake\Models\IntakeFollowUpRound;
use App\Enums\AircoConfigurationType;
use App\Enums\ContributionTaskStatus;
use App\Enums\CustomerLinkMailResult;
use App\Enums\FollowUpItemType;
use App\Enums\FollowUpRoundStatus;
use App\Enums\IntakeStatus;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Gates and drafts customer preference tasks for feasible installation options (BL-103).
 */
final class InstallationOptionPreferenceService
{
    public const META_KIND = 'installation_preference';

    public const NO_PREFERENCE_VALUE = 'no_preference';

    public function __construct(
        private readonly CreateCustomerContributionRequest $createContributionRequest,
        private readonly SendCustomerFollowUpRequest $sendFollowUpRequest,
    ) {}

    /** @return Collection<int, AircoInstallationOption> */
    public function feasibleOptions(Intake $intake): Collection
    {
        $intake->loadMissing('aircoInstallationOptions');

        return $intake->aircoInstallationOptions
            ->filter(static fn (AircoInstallationOption $option): bool => $option->isFeasible())
            ->sortBy('rank')
            ->values();
    }

    public function canRequestPreference(Intake $intake): bool
    {
        return $this->feasibleOptions($intake)->count() >= 2;
    }

    /** @return list<int> */
    public function feasibleOptionIds(Intake $intake): array
    {
        return $this->feasibleOptions($intake)
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->values()
            ->all();
    }

    public function fingerprint(Intake $intake): string
    {
        $ids = $this->feasibleOptionIds($intake);
        sort($ids);

        return implode(',', $ids);
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public function customerChoices(Intake $intake): array
    {
        $choices = $this->feasibleOptions($intake)
            ->map(fn (AircoInstallationOption $option): array => [
                'value' => $this->optionChoiceValue($option->id),
                'label' => $this->customerFacingLabel($option),
            ])
            ->values()
            ->all();

        $choices[] = [
            'value' => self::NO_PREFERENCE_VALUE,
            'label' => 'Geen voorkeur',
        ];

        return $choices;
    }

    public function draftPrompt(Intake $intake): string
    {
        $labels = collect($this->customerChoices($intake))
            ->reject(static fn (array $choice): bool => $choice['value'] === self::NO_PREFERENCE_VALUE)
            ->pluck('label')
            ->all();

        if ($labels === []) {
            return 'Welke installatie heeft jouw voorkeur?';
        }

        $list = implode(', of ', $labels);

        return 'Welke manier van plaatsen heeft jouw voorkeur: '.$list.'? Kies ook gerust Geen voorkeur.';
    }

    public function customerFacingLabel(AircoInstallationOption $option): string
    {
        $base = match ($option->configuration_type) {
            AircoConfigurationType::SingleSplit => 'Eén binnenunit met één buitenunit',
            AircoConfigurationType::MultiSplit => 'Eén gedeelde buitenunit voor alle ruimtes',
            AircoConfigurationType::MultipleSingleSplits => 'Een aparte buitenunit per ruimte',
        };

        $summary = trim((string) $option->summary);
        if ($summary !== '') {
            return $base.' — '.$this->plainConsequence($summary);
        }

        $label = trim($option->label);
        if ($label !== '' && ! str_contains(mb_strtolower($label), 'keuze')) {
            return $base.' — '.$this->plainConsequence($label);
        }

        return $base;
    }

    /**
     * @return array{
     *     available: bool,
     *     feasible_count: int,
     *     fingerprint: string,
     *     prompt: string,
     *     choices: list<array{value: string, label: string}>,
     *     stale_preference: array<string, mixed>|null
     * }
     */
    public function workspaceState(Intake $intake): array
    {
        $feasible = $this->feasibleOptions($intake);
        $available = $feasible->count() >= 2;

        return [
            'available' => $available,
            'feasible_count' => $feasible->count(),
            'fingerprint' => $this->fingerprint($intake),
            'prompt' => $available ? $this->draftPrompt($intake) : '',
            'choices' => $available ? $this->customerChoices($intake) : [],
            'stale_preference' => $this->latestStalePreferenceMeta($intake),
        ];
    }

    /**
     * Mark open/old preference answers stale when the feasible set changes.
     */
    public function invalidateIfFeasibleSetChanged(Intake $intake, User $actor): void
    {
        $currentFingerprint = $this->fingerprint($intake);
        $tasks = ContributionTask::query()
            ->where('intake_id', $intake->id)
            ->whereIn('status', [
                ContributionTaskStatus::Open,
                ContributionTaskStatus::Completed,
                ContributionTaskStatus::Proposed,
            ])
            ->get()
            ->filter(static function (ContributionTask $task): bool {
                $meta = is_array($task->meta) ? $task->meta : [];

                return ($meta['kind'] ?? null) === self::META_KIND;
            });

        if ($tasks->isEmpty()) {
            return;
        }

        $changed = false;

        foreach ($tasks as $task) {
            $meta = is_array($task->meta) ? $task->meta : [];
            $previous = (string) ($meta['feasible_fingerprint'] ?? '');
            if ($previous === '' || $previous === $currentFingerprint) {
                if (($meta['stale'] ?? false) === true && $previous === $currentFingerprint) {
                    $meta['stale'] = false;
                    $task->update(['meta' => $meta]);
                }

                continue;
            }

            $meta['stale'] = true;
            $meta['stale_at'] = now()->toIso8601String();
            $meta['stale_reason'] = 'feasible_set_changed';
            $meta['current_fingerprint'] = $currentFingerprint;
            $task->update(['meta' => $meta]);
            $changed = true;

            if ($task->status === ContributionTaskStatus::Open) {
                $this->cancelOpenPreferenceRound($intake, $actor, $task);
            }
        }

        if ($changed) {
            IntakeActivityEvent::query()->create([
                'intake_id' => $intake->id,
                'actor_type' => 'user',
                'actor_id' => $actor->id,
                'event' => 'installation_preference_stale',
                'properties' => [
                    'fingerprint' => $currentFingerprint,
                ],
                'created_at' => now(),
            ]);
        }
    }

    /**
     * @return array{round: IntakeFollowUpRound, mail: CustomerLinkMailResult}
     */
    public function requestPreference(Intake $intake, User $installer): array
    {
        if (! $this->canRequestPreference($intake)) {
            throw ValidationException::withMessages([
                'preference' => 'Vraag alleen een voorkeur als er minstens twee haalbare keuzes zijn.',
            ]);
        }

        $fingerprint = $this->fingerprint($intake);
        $choices = $this->customerChoices($intake);
        $prompt = $this->draftPrompt($intake);

        $round = $this->createContributionRequest->handle($intake, $installer, [
            [
                'type' => FollowUpItemType::Choice,
                'prompt' => $prompt,
                'decision_area_key' => 'placement',
                'meta' => [
                    'kind' => self::META_KIND,
                    'feasible_option_ids' => $this->feasibleOptionIds($intake),
                    'feasible_fingerprint' => $fingerprint,
                    'choices' => $choices,
                    'stale' => false,
                ],
            ],
        ]);

        $mail = $this->sendFollowUpRequest->handle($intake->fresh() ?? $intake, $round, $installer);

        IntakeActivityEvent::query()->create([
            'intake_id' => $intake->id,
            'actor_type' => 'user',
            'actor_id' => $installer->id,
            'event' => 'installation_preference_requested',
            'properties' => [
                'fingerprint' => $fingerprint,
                'choice_count' => count($choices),
                'round_number' => $round->round_number,
            ],
            'created_at' => now(),
        ]);

        return [
            'round' => $round,
            'mail' => $mail,
        ];
    }

    public function optionChoiceValue(int $optionId): string
    {
        return 'option:'.$optionId;
    }

    public function parsePreferredOptionId(?string $response): ?int
    {
        $response = trim((string) $response);
        if ($response === '' || $response === self::NO_PREFERENCE_VALUE) {
            return null;
        }

        if (preg_match('/^option:(\d+)$/', $response, $matches) !== 1) {
            return null;
        }

        return (int) $matches[1];
    }

    public function isNoPreference(?string $response): bool
    {
        return trim((string) $response) === self::NO_PREFERENCE_VALUE;
    }

    /** @return array<string, mixed>|null */
    private function latestStalePreferenceMeta(Intake $intake): ?array
    {
        $task = ContributionTask::query()
            ->where('intake_id', $intake->id)
            ->orderByDesc('id')
            ->get()
            ->first(static function (ContributionTask $task): bool {
                $meta = is_array($task->meta) ? $task->meta : [];

                return ($meta['kind'] ?? null) === self::META_KIND
                    && ($meta['stale'] ?? false) === true;
            });

        if (! $task instanceof ContributionTask) {
            return null;
        }

        $meta = is_array($task->meta) ? $task->meta : [];

        return array_merge($meta, [
            'task_id' => $task->id,
            'task_status' => $task->status->value,
        ]);
    }

    private function cancelOpenPreferenceRound(Intake $intake, User $actor, ContributionTask $task): void
    {
        DB::transaction(function () use ($intake, $actor, $task): void {
            $lockedIntake = Intake::query()->whereKey($intake->id)->lockForUpdate()->firstOrFail();
            $lockedTask = ContributionTask::query()->lockForUpdate()->findOrFail($task->id);

            if ($lockedTask->status !== ContributionTaskStatus::Open) {
                return;
            }

            $round = null;
            if ($lockedTask->intake_follow_up_item_id !== null) {
                $lockedTask->loadMissing('followUpItem.round');
                $round = $lockedTask->followUpItem?->round;
            }

            $lockedTask->update([
                'status' => ContributionTaskStatus::Cancelled,
                'completed_at' => null,
                'completed_by_type' => null,
                'completed_by_id' => null,
            ]);

            if ($round instanceof IntakeFollowUpRound && $round->status === FollowUpRoundStatus::Open) {
                $round->update([
                    'status' => FollowUpRoundStatus::Cancelled,
                    'completed_at' => now(),
                ]);

                ContributionTask::query()
                    ->where('intake_id', $lockedIntake->id)
                    ->where('status', ContributionTaskStatus::Open)
                    ->whereHas('followUpItem', static function ($query) use ($round): void {
                        $query->where('intake_follow_up_round_id', $round->id);
                    })
                    ->update([
                        'status' => ContributionTaskStatus::Cancelled,
                    ]);

                $lockedIntake->update([
                    'status' => $round->return_status ?? IntakeStatus::Draft,
                    'customer_access_enabled' => false,
                ]);
            }

            IntakeActivityEvent::query()->create([
                'intake_id' => $lockedIntake->id,
                'actor_type' => 'user',
                'actor_id' => $actor->id,
                'event' => 'installation_preference_cancelled',
                'properties' => [
                    'task_id' => $lockedTask->id,
                    'round_id' => $round?->id,
                ],
                'created_at' => now(),
            ]);
        }, 3);
    }

    private function plainConsequence(string $text): string
    {
        $text = trim(preg_replace('/\s+/', ' ', $text) ?? $text);
        if (mb_strlen($text) <= 120) {
            return $text;
        }

        return rtrim(mb_substr($text, 0, 117)).'…';
    }
}
