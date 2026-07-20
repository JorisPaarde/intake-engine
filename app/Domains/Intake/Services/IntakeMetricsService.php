<?php

declare(strict_types=1);

namespace App\Domains\Intake\Services;

use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeActivityEvent;
use App\Enums\QuestionType;
use App\Enums\ReviewDecision;
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

    /**
     * @return array{
     *     summary: array{
     *         created_count: int,
     *         started_count: int,
     *         completed_count: int,
     *         completion_percent: float|null,
     *         median_customer_duration_seconds: int|null,
     *         median_customer_actions: int|null,
     *         follow_up_rounds: int,
     *         average_follow_up_rounds: float|null,
     *         reviewed_count: int,
     *         enough_information_count: int,
     *         enough_information_percent: float|null,
     *         median_decision_seconds: int|null
     *     },
     *     intakes: list<array{
     *         id: int,
     *         reference: string,
     *         status: string,
     *         status_label: string,
     *         progress_percent: int,
     *         started: bool,
     *         completed: bool,
     *         customer_duration_seconds: int|null,
     *         customer_actions: int,
     *         follow_up_rounds: int,
     *         enough_information: bool|null,
     *         decision_seconds: int|null,
     *         dropout_key: string|null,
     *         dropout_label: string|null
     *     }>,
     *     dropoffs: list<array{key: string, label: string, count: int}>
     * }
     */
    public function calculate(?DateTimeInterface $createdSince = null): array
    {
        $intakes = Intake::query()
            ->where('is_demo', false)
            ->when($createdSince !== null, fn ($query) => $query->where('created_at', '>=', $createdSince))
            ->with([
                'answers:id,intake_id,question_key,prefill_source',
                'activityEvents:id,intake_id,actor_type,event,properties,created_at',
                'followUpRounds:id,intake_id,round_number,status,sent_at,completed_at',
                'review:id,intake_id,decision,enough_information,reviewed_at',
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
            ],
            'intakes' => $rows->all(),
            'dropoffs' => $dropoffs,
        ];
    }

    /**
     * @return array{
     *     id: int,
     *     reference: string,
     *     status: string,
     *     status_label: string,
     *     progress_percent: int,
     *     started: bool,
     *     completed: bool,
     *     customer_duration_seconds: int|null,
     *     customer_actions: int,
     *     follow_up_rounds: int,
     *     enough_information: bool|null,
     *     decision_seconds: int|null,
     *     dropout_key: string|null,
     *     dropout_label: string|null
     * }
     */
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

        $customerDuration = $intake->started_at !== null && $intake->completed_at !== null
            ? (int) max(0, $intake->started_at->diffInSeconds($intake->completed_at))
            : null;
        $decisionSeconds = $firstReviewAt !== null
            ? (int) max(0, $intake->created_at->diffInSeconds($firstReviewAt))
            : null;
        $dropoutKey = $intake->started_at !== null && $intake->completed_at === null
            ? $intake->current_question_key
            : null;

        return [
            'id' => $intake->id,
            'reference' => 'Opname #'.$intake->id,
            'status' => $intake->status->value,
            'status_label' => $intake->status->label(),
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
        ];
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
