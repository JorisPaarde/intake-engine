<?php

declare(strict_types=1);

namespace App\Domains\AI\Services;

use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeAnswer;
use App\Domains\Intake\Models\IntakeTemplateVersion;
use App\Domains\Intake\Services\IntakeStepBuilder;
use App\Domains\Intake\Services\VisibilityResolver;
use Throwable;

/**
 * Compact dossier / remaining-question snapshots for AI traces (no PII blobs).
 *
 * Dossier snapshots include redacted value summaries + changed_fields diffs.
 * Remaining-question snapshots reuse {@see IntakeStepBuilder::buildCatalog()}.
 */
class AiTraceSnapshotService
{
    public function __construct(
        private readonly IntakeStepBuilder $stepBuilder,
    ) {}

    /**
     * @return array{answers: list<array<string, mixed>>, answer_count: int, values: array<string, mixed>}
     */
    public function answers(Intake $intake): array
    {
        $values = [];
        $answers = $intake->answers()
            ->orderBy('id')
            ->get(['question_key', 'section_instance_key', 'prefill_source', 'value'])
            ->map(function (IntakeAnswer $answer) use (&$values): array {
                $composite = VisibilityResolver::compositeKey(
                    $answer->question_key,
                    $answer->section_instance_key,
                );
                $summary = $this->summarizeValue($answer->value);
                $values[$composite] = $summary;

                return [
                    'question_key' => $answer->question_key,
                    'section_instance_key' => $answer->section_instance_key,
                    'prefill_source' => $answer->prefill_source,
                    'value_keys' => is_array($answer->value) ? array_keys($answer->value) : [],
                    'has_value' => $answer->value !== null && $answer->value !== [],
                    'value' => $summary,
                ];
            })
            ->values()
            ->all();

        return [
            'answers' => $answers,
            'answer_count' => count($answers),
            'values' => $values,
        ];
    }

    /**
     * @param  array{answers: list<array<string, mixed>>, answer_count: int, values?: array<string, mixed>}  $before
     * @param  array{answers: list<array<string, mixed>>, answer_count: int, values?: array<string, mixed>}  $after
     * @return list<array{question_key: string, section_instance_key: string|null, change: string, before: mixed, after: mixed}>
     */
    public function changedFields(array $before, array $after): array
    {
        $beforeValues = $before['values'] ?? [];
        $afterValues = $after['values'] ?? [];
        $keys = array_values(array_unique([...array_keys($beforeValues), ...array_keys($afterValues)]));
        sort($keys);

        $changed = [];

        foreach ($keys as $composite) {
            $was = $beforeValues[$composite] ?? null;
            $is = $afterValues[$composite] ?? null;

            if ($was === $is) {
                continue;
            }

            [$questionKey, $sectionInstanceKey] = $this->splitComposite($composite);
            $change = match (true) {
                $was === null => 'added',
                $is === null => 'removed',
                default => 'updated',
            };

            $changed[] = [
                'question_key' => $questionKey,
                'section_instance_key' => $sectionInstanceKey,
                'change' => $change,
                'before' => $was,
                'after' => $is,
            ];
        }

        return $changed;
    }

    /**
     * @return array{
     *     questions: list<array<string, mixed>>,
     *     next_step: array<string, mixed>|null,
     *     visible_count: int,
     *     hidden_count: int,
     *     remaining_count: int
     * }
     */
    public function remainingQuestions(Intake $intake): array
    {
        try {
            $version = $intake->templateVersion()
                ->with(['sections.questions.options', 'sections.questions.rules'])
                ->first();

            if (! $version instanceof IntakeTemplateVersion) {
                return [
                    'questions' => [],
                    'next_step' => null,
                    'visible_count' => 0,
                    'hidden_count' => 0,
                    'remaining_count' => 0,
                ];
            }

            $catalog = $this->stepBuilder->buildCatalog($intake->fresh() ?? $intake, $version);
            $visibleCount = count(array_filter($catalog, static fn (array $row): bool => $row['visible'] === true));
            $hiddenCount = count($catalog) - $visibleCount;
            $remainingCount = $this->stepBuilder->remainingUnansweredVisibleCount($catalog);
            $next = $this->stepBuilder->nextUnansweredVisible($catalog);

            return [
                'questions' => $catalog,
                'next_step' => $next === null ? null : [
                    'question_key' => $next['question_key'],
                    'section_instance_key' => $next['section_instance_key'],
                    'section_key' => $next['section_key'],
                    'title' => $next['title'],
                    'reason' => $next['reason'],
                ],
                'visible_count' => $visibleCount,
                'hidden_count' => $hiddenCount,
                'remaining_count' => $remainingCount,
            ];
        } catch (Throwable) {
            return [
                'questions' => [],
                'next_step' => null,
                'visible_count' => 0,
                'hidden_count' => 0,
                'remaining_count' => 0,
            ];
        }
    }

    /**
     * Compact value summary safe for traces (no long free-text / binary).
     */
    private function summarizeValue(mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        if (! is_array($value)) {
            if (is_string($value) && strlen($value) > 120) {
                return substr($value, 0, 117).'…';
            }

            return $value;
        }

        $out = [];
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $out[$key] = ['keys' => array_keys($item), 'count' => count($item)];

                continue;
            }

            if (is_string($item) && strlen($item) > 80) {
                $out[$key] = substr($item, 0, 77).'…';

                continue;
            }

            $out[$key] = $item;
        }

        return $out;
    }

    /**
     * @return array{0: string, 1: string|null}
     */
    private function splitComposite(string $composite): array
    {
        if (! str_contains($composite, '|')) {
            return [$composite, null];
        }

        [$questionKey, $sectionInstanceKey] = explode('|', $composite, 2);

        return [$questionKey, $sectionInstanceKey === '' ? null : $sectionInstanceKey];
    }
}
