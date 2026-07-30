<?php

declare(strict_types=1);

namespace App\Domains\Intake\Services;

use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeActivityEvent;
use App\Enums\ContributionMode;
use App\Enums\InstallationProposalDelta;
use App\Enums\InstallationSiteVisitReason;
use App\Enums\QuestionType;
use App\Enums\ReviewDecision;
use App\Models\Company;
use DateTimeInterface;
use Illuminate\Support\Collection;

final class IntakeMetricsService
{
    /** @var list<string> */
    private const CUSTOMER_ACTION_EVENTS = [
        'upload_stored',
        'upload_deleted',
        'follow_up_text_saved',
        'follow_up_upload_stored',
        'follow_up_upload_deleted',
        'intake_completed',
        'follow_up_completed',
    ];

    /** @return array<string, mixed> */
    public function calculate(Company $company, ?DateTimeInterface $createdSince = null): array
    {
        $intakes = Intake::query()
            ->where('is_demo', false)
            ->where('company_id', $company->id)
            ->when($createdSince !== null, fn ($query) => $query->where('created_at', '>=', $createdSince))
            ->with([
                'answers:id,intake_id,question_key,prefill_source',
                'activityEvents:id,intake_id,actor_type,event,properties,created_at',
                'followUpRounds:id,intake_id,round_number,status,sent_at,completed_at',
                'review:id,intake_id,decision,enough_information,reviewed_at',
                'outcome',
                'templateVersion.sections.questions:id,intake_section_id,key,label,type',
            ])
            ->latest()
            ->get();

        $rows = $intakes
            ->map(fn (Intake $intake): array => $this->forIntake($intake))
            ->values();

        $started = $rows->where('started', true);
        $completed = $started->where('completed', true);
        $reviewed = $rows->filter(static fn (array $row): bool => $row['enough_information'] !== null);
        $decisionSeconds = $rows->pluck('decision_seconds')->filter(static fn (mixed $value): bool => is_int($value));
        $durations = $completed->pluck('customer_duration_seconds')->filter(static fn (mixed $value): bool => is_int($value));
        $actions = $started->pluck('customer_actions')->map(static fn (mixed $value): int => (int) $value);
        $followUpRounds = $rows->sum('follow_up_rounds');
        $outcomes = $rows->where('outcome_recorded', true);
        $remoteQuotes = $outcomes->where('remote_quote', true);
        $estimates = $outcomes->where('price_estimate', true);
        $siteVisits = $outcomes->where('site_visit_occurred', true);
        $activeInstallerMinutes = $outcomes
            ->pluck('active_installer_minutes')
            ->filter(static fn (mixed $value): bool => is_int($value));
        $recordedCustomerMinutes = $outcomes
            ->pluck('recorded_customer_minutes')
            ->filter(static fn (mixed $value): bool => is_int($value));
        $measuredInstallations = $outcomes->filter(
            static fn (array $row): bool => $row['installation_surprise'] !== null,
        );
        $installationSurprises = $measuredInstallations->filter(
            static fn (array $row): bool => in_array($row['installation_surprise'], ['minor', 'major'], true),
        );
        $measuredProposals = $outcomes->where('proposal_assessed', true);
        $changedProposals = $measuredProposals->filter(
            static fn (array $row): bool => $row['proposal_delta_codes'] !== [],
        );
        $siteVisitReasonCounts = $siteVisits
            ->flatMap(static fn (array $row): array => $row['site_visit_reasons'])
            ->countBy();
        $proposalDeltaCounts = $measuredProposals
            ->flatMap(static fn (array $row): array => $row['proposal_delta_codes'])
            ->countBy();

        $dropoffs = $rows
            ->filter(static fn (array $row): bool => $row['started'] && ! $row['completed'])
            ->groupBy(static fn (array $row): string => $row['dropout_key'] ?? 'unknown')
            ->map(static function (Collection $rows, string $key): array {
                /** @var array{dropout_label: string|null} $first */
                $first = $rows->first();

                return [
                    'key' => $key,
                    'label' => $first['dropout_label'] ?? 'Onbekend uitvalpunt',
                    'count' => $rows->count(),
                ];
            })
            ->sortByDesc('count')
            ->values()
            ->all();

        return [
            'summary' => [
                'created_count' => $rows->count(),
                'started_count' => $started->count(),
                'completed_count' => $completed->count(),
                'completion_percent' => $this->percentage($completed->count(), $started->count()),
                'median_customer_duration_seconds' => $this->median($durations->all()),
                'median_customer_actions' => $this->median($actions->all()),
                'follow_up_rounds' => (int) $followUpRounds,
                'average_follow_up_rounds' => $started->isEmpty()
                    ? null
                    : round($followUpRounds / $started->count(), 1),
                'reviewed_count' => $reviewed->count(),
                'enough_information_count' => $reviewed->where('enough_information', true)->count(),
                'enough_information_percent' => $this->percentage(
                    $reviewed->where('enough_information', true)->count(),
                    $reviewed->count(),
                ),
                'median_decision_seconds' => $this->median($decisionSeconds->all()),
                'outcome_recorded_count' => $outcomes->count(),
                'remote_quote_count' => $remoteQuotes->count(),
                'remote_quote_percent' => $this->percentage($remoteQuotes->count(), $outcomes->count()),
                'price_estimate_count' => $estimates->count(),
                'price_estimate_percent' => $this->percentage($estimates->count(), $outcomes->count()),
                'site_visit_count' => $siteVisits->count(),
                'site_visit_percent' => $this->percentage($siteVisits->count(), $outcomes->count()),
                'site_visit_reasons' => collect(InstallationSiteVisitReason::cases())
                    ->map(static fn (InstallationSiteVisitReason $reason): array => [
                        'code' => $reason->value,
                        'label' => $reason->label(),
                        'count' => (int) $siteVisitReasonCounts->get($reason->value, 0),
                    ])
                    ->where('count', '>', 0)
                    ->values()
                    ->all(),
                'median_active_installer_minutes' => $this->median($activeInstallerMinutes->all()),
                'median_recorded_customer_minutes' => $this->median($recordedCustomerMinutes->all()),
                'measured_proposal_count' => $measuredProposals->count(),
                'changed_proposal_count' => $changedProposals->count(),
                'changed_proposal_percent' => $this->percentage(
                    $changedProposals->count(),
                    $measuredProposals->count(),
                ),
                'proposal_delta_types' => collect(InstallationProposalDelta::cases())
                    ->map(static fn (InstallationProposalDelta $delta): array => [
                        'code' => $delta->value,
                        'label' => $delta->label(),
                        'count' => (int) $proposalDeltaCounts->get($delta->value, 0),
                    ])
                    ->where('count', '>', 0)
                    ->values()
                    ->all(),
                'measured_installation_count' => $measuredInstallations->count(),
                'installation_surprise_count' => $installationSurprises->count(),
                'installation_surprise_percent' => $this->percentage(
                    $installationSurprises->count(),
                    $measuredInstallations->count(),
                ),
            ],
            'intakes' => $rows->all(),
            'dropoffs' => $dropoffs,
        ];
    }

    /** @return array<string, mixed> */
    private function forIntake(Intake $intake): array
    {
        $questionTypes = [];
        $questionLabels = [];

        foreach ($intake->templateVersion->sections as $section) {
            foreach ($section->questions as $question) {
                $questionTypes[$question->key] = $question->type;
                $questionLabels[$question->key] = $question->label;
            }
        }

        $answerRecords = $intake->answers->filter(
            static fn ($answer): bool => $answer->prefill_source === null
                && ($questionTypes[$answer->question_key] ?? null) !== QuestionType::Photo,
        )->count();

        $customerEvents = $intake->activityEvents->where('actor_type', 'customer');
        $answerSaveEvents = $customerEvents->where('event', 'answer_saved')->count();
        $otherActionEvents = $customerEvents->whereIn('event', self::CUSTOMER_ACTION_EVENTS)->count();
        $reviewEvents = $intake->activityEvents
            ->where('event', 'intake_reviewed')
            ->sortBy('created_at')
            ->values();
        $firstReviewEvent = $reviewEvents->isEmpty() ? null : $reviewEvents->first();
        $firstReviewAt = $firstReviewEvent === null
            ? $intake->reviewed_at
            : $firstReviewEvent->created_at;

        $customerDuration = $intake->workflow_mode === ContributionMode::Customer
            && $intake->started_at !== null
            && $intake->completed_at !== null
            ? (int) max(0, $intake->started_at->diffInSeconds($intake->completed_at))
            : null;
        $decisionSeconds = $firstReviewAt !== null
            ? (int) max(0, $intake->created_at->diffInSeconds($firstReviewAt))
            : null;
        $dropoutKey = $intake->started_at !== null && $intake->completed_at === null
            ? $intake->current_question_key
            : null;
        $outcome = $intake->outcome;
        $remoteQuote = $outcome !== null
            && ! $outcome->site_visit_occurred
            && ($outcome->result === 'remote_quote' || $outcome->quote_type === 'remote');
        $proposalDelta = is_array($outcome?->proposal_delta)
            ? $outcome->proposal_delta
            : null;
        $proposalDeltaCodes = is_array($proposalDelta['codes'] ?? null)
            ? array_values(array_filter(
                $proposalDelta['codes'],
                static fn (mixed $code): bool => is_string($code)
                    && InstallationProposalDelta::tryFrom($code) !== null,
            ))
            : [];
        $siteVisitReasons = is_array($outcome?->site_visit_reasons)
            ? array_values(array_filter(
                $outcome->site_visit_reasons,
                static fn (mixed $reason): bool => is_string($reason)
                    && InstallationSiteVisitReason::tryFrom($reason) !== null,
            ))
            : [];

        return [
            'id' => $intake->id,
            'reference' => 'Opname #'.$intake->id,
            'status' => $intake->status->value,
            'status_label' => $intake->status->label(),
            'workflow_mode' => $intake->workflow_mode->value,
            'workflow_label' => $intake->workflow_mode->label(),
            'progress_percent' => $intake->progress_percent,
            'started' => $intake->started_at !== null,
            'completed' => $intake->completed_at !== null,
            'customer_duration_seconds' => $customerDuration,
            'customer_actions' => max($answerRecords, $answerSaveEvents) + $otherActionEvents,
            'follow_up_rounds' => $intake->followUpRounds->count(),
            'enough_information' => $this->directEnoughInformation(
                $intake,
                $firstReviewEvent,
                $reviewEvents->count(),
            ),
            'decision_seconds' => $decisionSeconds,
            'dropout_key' => $dropoutKey,
            'dropout_label' => $dropoutKey !== null
                ? ($questionLabels[$dropoutKey] ?? 'Onbekend uitvalpunt')
                : null,
            'outcome_recorded' => $outcome !== null,
            'outcome_result' => $outcome?->result,
            'outcome_label' => $this->outcomeLabel($outcome?->result),
            'remote_quote' => $remoteQuote,
            'price_estimate' => $outcome?->result === 'estimate' || $outcome?->quote_type === 'estimate',
            'site_visit_occurred' => $outcome?->site_visit_occurred ?? false,
            'site_visit_reasons' => $siteVisitReasons,
            'active_installer_minutes' => $outcome?->active_installer_minutes,
            'recorded_customer_minutes' => $outcome?->customer_minutes,
            'proposal_assessed' => $proposalDelta !== null,
            'proposal_delta_codes' => $proposalDeltaCodes,
            'installation_surprise' => $outcome?->installation_surprise,
        ];
    }

    private function outcomeLabel(?string $result): ?string
    {
        return match ($result) {
            'remote_quote' => 'Op afstand geoffreerd',
            'estimate' => 'Prijsindicatie',
            'site_visit' => 'Locatiebezoek',
            'rejected' => 'Afgewezen',
            'installed' => 'Geplaatst',
            default => null,
        };
    }

    private function directEnoughInformation(
        Intake $intake,
        ?IntakeActivityEvent $firstReviewEvent,
        int $reviewEventCount,
    ): ?bool {
        if ($firstReviewEvent === null) {
            return $intake->review?->reviewed_at !== null
                ? $intake->review->enough_information
                : null;
        }

        $properties = $firstReviewEvent->properties;
        $recorded = $properties['enough_information'] ?? null;

        if (is_bool($recorded)) {
            return $recorded;
        }

        if ($recorded === 0 || $recorded === 1) {
            return (bool) $recorded;
        }

        $decision = is_string($properties['decision'] ?? null)
            ? ReviewDecision::tryFrom($properties['decision'])
            : null;

        if ($decision === ReviewDecision::NeedMoreInfo) {
            return false;
        }

        if ($intake->review?->reviewed_at !== null
            && ($reviewEventCount === 1 || $decision === $intake->review->decision)) {
            return $intake->review->enough_information;
        }

        return $decision === null ? null : true;
    }

    /** @param list<int> $values */
    private function median(array $values): ?int
    {
        if ($values === []) {
            return null;
        }

        sort($values, SORT_NUMERIC);
        $middle = intdiv(count($values), 2);

        if (count($values) % 2 === 1) {
            return $values[$middle];
        }

        return (int) round(($values[$middle - 1] + $values[$middle]) / 2);
    }

    private function percentage(int $part, int $total): ?float
    {
        return $total === 0 ? null : round(($part / $total) * 100, 1);
    }
}
