<?php

declare(strict_types=1);

namespace App\Domains\Intake\Services;

use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeQuestion;
use App\Domains\Intake\Models\IntakeSection;
use App\Domains\Intake\Models\IntakeTemplateVersion;
use App\Enums\QuestionType;
use Illuminate\Support\Str;

final class CompletenessChecker
{
    public function __construct(
        private readonly ProgressCalculator $progressCalculator,
    ) {}

    /**
     * @return array{
     *     is_complete: bool,
     *     missing: list<array{question_key: string, section_instance_key: string|null, reason: string, label: string, instance_label: string|null}>,
     *     attention_points: list<array{code: string, label: string}>
     * }
     */
    public function check(Intake $intake, IntakeTemplateVersion $version): array
    {
        $version->loadMissing(['sections.questions.options', 'sections.questions.rules']);

        $progress = $this->progressCalculator->calculate($intake, $version);
        $missing = [];

        foreach ($progress['missing_required'] as $item) {
            $question = $this->findQuestion($version, $item['question_key']);
            $section = $this->findSectionForQuestion($version, $item['question_key']);
            $reason = $question !== null && $question->type === QuestionType::Photo
                ? 'required_photo'
                : 'required_answer';

            $missing[] = [
                'question_key' => $item['question_key'],
                'section_instance_key' => $item['section_instance_key'],
                'reason' => $reason,
                'label' => $question !== null ? $question->label : $item['question_key'],
                'instance_label' => $this->instanceLabel($section, $item['section_instance_key']),
            ];
        }

        return [
            'is_complete' => $missing === [],
            'missing' => $missing,
            'attention_points' => $this->attentionPoints($intake),
        ];
    }

    /**
     * @return list<array{code: string, label: string}>
     */
    private function attentionPoints(Intake $intake): array
    {
        $intake->loadMissing(['answers']);
        $points = [];

        $freeGroup = $intake->answers
            ->first(static fn ($answer): bool => $answer->question_key === 'free_group_known'
                && $answer->section_instance_key === null);

        $value = is_array($freeGroup?->value) ? ($freeGroup->value['value'] ?? null) : null;

        if ($value === 'no') {
            $points[] = [
                'code' => 'no_free_group',
                'label' => 'Geen vrije groep bekend',
            ];
        }

        if ($value === 'unknown') {
            $points[] = [
                'code' => 'free_group_unknown',
                'label' => 'Onbekend of er een vrije groep beschikbaar is',
            ];
        }

        $naturalFall = $intake->answers
            ->first(static fn ($answer): bool => $answer->question_key === 'natural_fall_possible'
                && $answer->section_instance_key === null);

        $naturalFallValue = is_array($naturalFall?->value) ? ($naturalFall->value['bool'] ?? null) : null;

        if ($naturalFallValue === false) {
            $points[] = [
                'code' => 'condensate_pump_likely',
                'label' => 'Natuurlijk afschot waarschijnlijk niet mogelijk — pomp mogelijk nodig',
            ];
        }

        return $points;
    }

    private function findQuestion(IntakeTemplateVersion $version, string $questionKey): ?IntakeQuestion
    {
        foreach ($version->sections as $section) {
            foreach ($section->questions as $question) {
                if ($question->key === $questionKey) {
                    return $question;
                }
            }
        }

        return null;
    }

    private function findSectionForQuestion(IntakeTemplateVersion $version, string $questionKey): ?IntakeSection
    {
        foreach ($version->sections as $section) {
            foreach ($section->questions as $question) {
                if ($question->key === $questionKey) {
                    return $section;
                }
            }
        }

        return null;
    }

    /**
     * Same readable pattern as IntakeStepBuilder: "Ruimtes 2" instead of "room-2".
     */
    private function instanceLabel(?IntakeSection $section, ?string $sectionInstanceKey): ?string
    {
        if ($sectionInstanceKey === null || $sectionInstanceKey === '') {
            return null;
        }

        if ($section instanceof IntakeSection) {
            return $section->title.' '.Str::afterLast($sectionInstanceKey, '-');
        }

        return $sectionInstanceKey;
    }
}
