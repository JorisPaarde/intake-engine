<?php

declare(strict_types=1);

namespace App\Domains\AI\Services;

use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeUpload;
use App\Enums\AttentionPointSource;

/**
 * Builds the bounded technical dossier supplied to attention-point analysis.
 *
 * Customer identity/address fields, storage paths and file bytes are deliberately
 * excluded. Source provenance and uncertainty remain explicit so AI output cannot
 * silently turn an inference into an authoritative fact.
 */
final class IntakeAttentionContextBuilder
{
    /** @return array<string, mixed> */
    public function build(Intake $intake): array
    {
        $intake->loadMissing([
            'answers',
            'externalFacts',
            'uploads',
            'attentionPoints',
            'followUpRounds.items.uploads',
            'review',
            'pipeRouteSessions.segments',
            'templateVersion.template',
            'templateVersion.sections.questions',
        ]);

        $answers = [];
        $answerContext = [];
        $questions = $this->questionContext($intake);

        foreach ($intake->answers as $answer) {
            $key = $answer->section_instance_key
                ? $answer->section_instance_key.'__'.$answer->question_key
                : $answer->question_key;
            $answers[$key] = $answer->value;

            $question = $questions[$answer->question_key] ?? [
                'question_label' => $answer->question_key,
                'section_key' => null,
                'section_label' => null,
            ];

            $answerContext[] = [
                'question_key' => $answer->question_key,
                'question_label' => $question['question_label'],
                'section_key' => $question['section_key'],
                'section_label' => $question['section_label'],
                'section_instance_key' => $answer->section_instance_key,
                'answer' => $answer->value,
                'prefill_source' => $answer->prefill_source,
            ];
        }

        $externalFacts = [];
        $externalFactContext = [];

        foreach ($intake->externalFacts as $fact) {
            $value = $this->removeStorageReferences($fact->value);
            $externalFacts[$fact->fact_key] = [
                'value' => $value,
                'source' => $fact->source,
                'confidence' => $fact->confidence,
            ];
            $externalFactContext[] = [
                'fact_key' => $fact->fact_key,
                'label' => $fact->label,
                'value' => $value,
                'source' => $fact->source,
                'source_reference' => $fact->source_reference,
                'confidence' => $fact->confidence,
                'captured_at' => $fact->captured_at->toIso8601String(),
            ];
        }

        return [
            // Kept for deterministic/local providers and backwards-compatible prompts.
            'answers' => $answers,
            'external_facts' => $externalFacts,
            // Rich, provenance-aware context for the integral analysis.
            'answer_context' => $answerContext,
            'external_fact_context' => $externalFactContext,
            'uploads' => $intake->uploads->map(
                fn (IntakeUpload $upload): array => $this->uploadContext(
                    $upload,
                    $questions[$upload->question_key] ?? null,
                ),
            )->values()->all(),
            'follow_up' => $this->followUpContext($intake),
            'system_attention_points' => $intake->attentionPoints
                ->reject(fn ($point): bool => $point->source === AttentionPointSource::Ai)
                ->map(fn ($point): array => [
                    'source' => $point->source->value,
                    'code' => $point->code,
                    'label' => $point->label,
                    'is_resolved' => $point->is_resolved,
                ])
                ->values()
                ->all(),
            'completeness' => $intake->completeness_snapshot ?? [],
            'installer_review' => $intake->review === null ? null : [
                'decision' => $intake->review->decision->value,
                'site_visit_needed' => $intake->review->site_visit_needed,
                'enough_information' => $intake->review->enough_information,
                'summary' => $intake->review->summary,
            ],
            'pipe_routes' => $this->pipeRouteContext($intake),
            'template_key' => $intake->templateVersion?->template?->key,
            'template_version' => $intake->templateVersion?->version,
            'intake_status' => $intake->status->value,
        ];
    }

    /**
     * @return array<string, array{question_label: string, section_key: string|null, section_label: string|null}>
     */
    private function questionContext(Intake $intake): array
    {
        $context = [];

        foreach ($intake->templateVersion->sections as $section) {
            foreach ($section->questions as $question) {
                $context[$question->key] = [
                    'question_label' => (string) $question->label,
                    'section_key' => $section->key,
                    'section_label' => (string) $section->title,
                ];
            }
        }

        return $context;
    }

    /** @return list<array<string, mixed>> */
    private function pipeRouteContext(Intake $intake): array
    {
        $routes = [];

        foreach ($intake->pipeRouteSessions as $session) {
            $segments = [];

            foreach ($session->segments as $segment) {
                $segments[] = [
                    'sequence' => $segment->sequence,
                    'label' => $segment->label,
                    'photo_usable' => $segment->photo_usable,
                    'route_possible' => $segment->route_possible,
                    'confidence' => $segment->confidence,
                    'analysis' => is_array($segment->analysis)
                        ? $this->removeStorageReferences($segment->analysis)
                        : null,
                ];
            }

            $routes[] = [
                'status' => $session->status->value,
                'confidence' => $session->confidence,
                'proposed_route' => $session->proposed_route,
                'alternative_route' => $session->alternative_route,
                'uncertainties' => $session->uncertainties,
                'missing_checks' => $session->missing_checks,
                'next_photo_instruction' => $session->next_photo_instruction,
                'segments' => $segments,
            ];
        }

        return $routes;
    }

    /**
     * @param  array{question_label: string, section_key: string|null, section_label: string|null}|null  $question
     * @return array<string, mixed>
     */
    private function uploadContext(IntakeUpload $upload, ?array $question = null): array
    {
        return [
            'question_key' => $upload->question_key,
            'question_label' => $question['question_label'] ?? null,
            'section_label' => $question['section_label'] ?? null,
            'section_instance_key' => $upload->section_instance_key,
            'context' => $upload->intake_follow_up_item_id === null ? 'intake' : 'follow_up',
            'mime_type' => $upload->mime_type,
            'size_bytes' => $upload->size_bytes,
            'usability_verdict' => $upload->usability_verdict?->value,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function followUpContext(Intake $intake): array
    {
        $rounds = [];

        foreach ($intake->followUpRounds as $round) {
            $items = [];

            foreach ($round->items as $item) {
                $items[] = [
                    'type' => $item->type->value,
                    'prompt' => $item->prompt,
                    'response_text' => $item->response_text,
                    'uploads' => $item->uploads
                        ->map(fn (IntakeUpload $upload): array => $this->uploadContext($upload))
                        ->values()
                        ->all(),
                ];
            }

            $rounds[] = [
                'round_number' => $round->round_number,
                'status' => $round->status->value,
                'items' => $items,
            ];
        }

        return $rounds;
    }

    /**
     * @param  array<string, mixed>  $value
     * @return array<string, mixed>
     */
    private function removeStorageReferences(array $value): array
    {
        $blocked = ['path', 'media_path', 'disk', 'media_disk'];
        $clean = [];

        foreach ($value as $key => $item) {
            if (in_array((string) $key, $blocked, true)) {
                continue;
            }

            $clean[$key] = is_array($item) ? $this->removeStorageReferences($item) : $item;
        }

        return $clean;
    }
}
