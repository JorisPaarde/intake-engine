<?php

declare(strict_types=1);

namespace App\Domains\AI\Services;

use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeAnswer;
use App\Domains\Intake\Models\IntakeTemplateVersion;
use App\Domains\Intake\Services\IntakeStepBuilder;
use Throwable;

/**
 * Compact dossier / remaining-question snapshots for AI traces (no PII blobs).
 */
final class AiTraceSnapshotService
{
    public function __construct(
        private readonly IntakeStepBuilder $stepBuilder,
    ) {}

    /**
     * @return array{answers: list<array<string, mixed>>, answer_count: int}
     */
    public function answers(Intake $intake): array
    {
        $answers = $intake->answers()
            ->orderBy('id')
            ->get(['question_key', 'section_instance_key', 'prefill_source', 'value'])
            ->map(function (IntakeAnswer $answer): array {
                return [
                    'question_key' => $answer->question_key,
                    'section_instance_key' => $answer->section_instance_key,
                    'prefill_source' => $answer->prefill_source,
                    'value_keys' => is_array($answer->value) ? array_keys($answer->value) : [],
                    'has_value' => $answer->value !== null && $answer->value !== [],
                ];
            })
            ->values()
            ->all();

        return [
            'answers' => $answers,
            'answer_count' => count($answers),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function remainingQuestions(Intake $intake): array
    {
        try {
            $version = $intake->templateVersion()
                ->with(['sections.questions.options', 'sections.questions.rules'])
                ->first();

            if (! $version instanceof IntakeTemplateVersion) {
                return [];
            }

            $steps = $this->stepBuilder->build($intake->fresh() ?? $intake, $version);

            return array_map(static function (array $step): array {
                return [
                    'question_key' => $step['question_key'],
                    'section_instance_key' => $step['section_instance_key'],
                    'section_key' => $step['section_key'],
                    'visible' => true,
                    'reason' => 'visible_in_customer_steps',
                ];
            }, $steps);
        } catch (Throwable) {
            return [];
        }
    }
}
