<?php

declare(strict_types=1);

namespace App\Domains\Intake\Services;

use App\Domains\Intake\Models\IntakeQuestion;
use App\Domains\Intake\Models\IntakeQuestionOption;
use App\Domains\Intake\Models\IntakeQuestionRule;
use App\Domains\Intake\Models\IntakeSection;
use App\Domains\Intake\Models\IntakeTemplate;
use App\Domains\Intake\Models\IntakeTemplateVersion;
use App\Enums\QuestionType;
use App\Enums\RuleEffect;
use App\Enums\RuleOperator;
use App\Enums\TemplateVersionStatus;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class PublishIntakeTemplateFromConfig
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function handle(array $config): IntakeTemplateVersion
    {
        return DB::transaction(function () use ($config): IntakeTemplateVersion {
            $template = IntakeTemplate::query()->updateOrCreate(
                ['key' => $config['key']],
                [
                    'name' => $config['name'],
                    'description' => $config['description'] ?? null,
                    'is_active' => true,
                ],
            );

            $existing = IntakeTemplateVersion::query()
                ->where('intake_template_id', $template->id)
                ->where('version', $config['version'])
                ->first();

            if ($existing !== null) {
                if ($existing->status === TemplateVersionStatus::Published) {
                    return $existing->load(['sections.questions.options', 'sections.questions.rules']);
                }

                throw new RuntimeException("Draft version {$config['version']} for [{$config['key']}] already exists.");
            }

            $version = IntakeTemplateVersion::query()->create([
                'intake_template_id' => $template->id,
                'version' => $config['version'],
                'status' => TemplateVersionStatus::Published,
                'published_at' => now(),
                'change_notes' => $config['change_notes'] ?? null,
            ]);

            foreach ($config['sections'] as $sectionData) {
                $section = IntakeSection::query()->create([
                    'intake_template_version_id' => $version->id,
                    'key' => $sectionData['key'],
                    'title' => $sectionData['title'],
                    'description' => $sectionData['description'] ?? null,
                    'sort_order' => $sectionData['sort_order'],
                    'is_repeatable' => $sectionData['is_repeatable'] ?? false,
                    'repeat_count_question_key' => $sectionData['repeat_count_question_key'] ?? null,
                ]);

                foreach ($sectionData['questions'] as $questionData) {
                    $question = IntakeQuestion::query()->create([
                        'intake_section_id' => $section->id,
                        'key' => $questionData['key'],
                        'type' => QuestionType::from($questionData['type']),
                        'label' => $questionData['label'],
                        'help_text' => $questionData['help_text'] ?? null,
                        'photo_instructions' => $questionData['photo_instructions'] ?? null,
                        'is_required' => $questionData['is_required'] ?? false,
                        'sort_order' => $questionData['sort_order'],
                        'validation_rules' => $questionData['validation_rules'] ?? null,
                        'meta' => $questionData['meta'] ?? null,
                    ]);

                    foreach ($questionData['options'] ?? [] as $optionData) {
                        IntakeQuestionOption::query()->create([
                            'intake_question_id' => $question->id,
                            'value' => $optionData['value'],
                            'label' => $optionData['label'],
                            'sort_order' => $optionData['sort_order'],
                        ]);
                    }

                    foreach ($questionData['rules'] ?? [] as $ruleData) {
                        IntakeQuestionRule::query()->create([
                            'intake_question_id' => $question->id,
                            'source_question_key' => $ruleData['source_question_key'],
                            'operator' => RuleOperator::from($ruleData['operator']),
                            'value' => $ruleData['value'] ?? null,
                            'effect' => RuleEffect::from($ruleData['effect']),
                        ]);
                    }
                }
            }

            return $version->load(['sections.questions.options', 'sections.questions.rules']);
        });
    }
}
