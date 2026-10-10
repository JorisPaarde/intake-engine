<?php

declare(strict_types=1);

namespace App\Domains\AI\Services;

use App\Domains\AI\Support\PhotoContentAssessment;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeQuestion;
use App\Domains\Intake\Models\IntakeUpload;
use App\Domains\Intake\Support\PhotoContinueAnywayAttention;
use App\Domains\Intake\Support\UploadSupersessionResolver;
use App\Enums\AttentionPointSource;
use App\Enums\QuestionType;
use Illuminate\Support\Str;

/**
 * Builds the bounded technical dossier supplied to attention-point analysis.
 *
 * Customer identity/address fields, storage paths and file bytes are deliberately
 * excluded. Source provenance and uncertainty remain explicit so AI output cannot
 * silently turn an inference into an authoritative fact.
 */
final class IntakeAttentionContextBuilder
{
    private const MAX_PAYLOAD_BYTES = 100_000;

    private const INITIAL_VALUE_BUDGET = 80_000;

    private const MAX_STRING_LENGTH = 2_000;

    private const MAX_LIST_ITEMS = 100;

    /** @var list<string> */
    private const SENSITIVE_FACT_KEYS = ['location', 'parcel_ids', 'aerial_image'];

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
            $safeValue = is_array($answer->value)
                ? $this->removeSensitiveReferences($answer->value)
                : $answer->value;
            $answers[$key] = $safeValue;

            $question = $questions[$answer->question_key] ?? [
                'question_label' => $answer->question_key,
                'section_key' => null,
                'section_label' => null,
            ];

            $row = [
                'reference' => $this->questionReference($answer->question_key, $answer->section_instance_key),
                'question_key' => $answer->question_key,
                'question_label' => $question['question_label'],
                'section_key' => $question['section_key'],
                'section_label' => $question['section_label'],
                'section_instance_key' => $answer->section_instance_key,
                'answer' => $safeValue,
                'prefill_source' => $answer->prefill_source,
            ];

            $optionLabel = $this->optionLabelForAnswer(
                $questions[$answer->question_key]['question'] ?? null,
                is_array($safeValue) ? $safeValue : null,
            );
            if ($optionLabel !== null) {
                $row['option_label'] = $optionLabel;
            }

            $answerContext[] = $row;
        }

        $externalFacts = [];
        $externalFactContext = [];

        foreach ($intake->externalFacts as $fact) {
            if (in_array($fact->fact_key, self::SENSITIVE_FACT_KEYS, true)) {
                continue;
            }

            $value = $this->removeSensitiveReferences($fact->value);
            $externalFacts[$fact->fact_key] = [
                'value' => $value,
                'source' => $fact->source,
                'confidence' => $fact->confidence,
            ];
            $externalFactContext[] = [
                'reference' => $fact->fact_key.'@fact:'.$this->opaqueReference('fact', $fact->id),
                'fact_key' => $fact->fact_key,
                'label' => $fact->label,
                'value' => $value,
                'source' => $fact->source,
                'confidence' => $fact->confidence,
                'captured_at' => $fact->captured_at->toIso8601String(),
            ];
        }

        $payload = [
            // Kept for deterministic/local providers and backwards-compatible prompts.
            'answers' => $answers,
            'external_facts' => $externalFacts,
            // Rich, provenance-aware context for the integral analysis.
            'answer_context' => $answerContext,
            'external_fact_context' => $externalFactContext,
            'uploads' => $intake->uploads
                ->filter(static fn (IntakeUpload $upload): bool => $upload->isDossierEvidenceEligible())
                ->map(
                    fn (IntakeUpload $upload): array => $this->uploadContext(
                        $upload,
                        $questions[$upload->question_key] ?? null,
                    ),
                )->values()->all(),
            'photo_question_stats' => $this->photoQuestionStats($intake, $questions),
            'follow_up' => $this->followUpContext($intake),
            'system_attention_points' => $intake->attentionPoints
                ->reject(fn ($point): bool => $point->source === AttentionPointSource::Ai)
                ->map(fn ($point): array => [
                    'reference' => $point->code,
                    'source' => $point->source->value,
                    'code' => $point->code,
                    'label' => $point->label,
                    'is_resolved' => $point->is_resolved,
                ])
                ->values()
                ->all(),
            'completeness' => $intake->completeness_snapshot ?? [],
            'installer_review' => $intake->review === null ? null : [
                'reference' => 'review',
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

        return $this->boundedPayload($payload);
    }

    /**
     * @param  array<string, mixed>  $value
     * @return array<string, mixed>
     */
    public function sanitizeExternalValue(array $value): array
    {
        return $this->removeSensitiveReferences($value);
    }

    public function isSensitiveFactKey(string $factKey): bool
    {
        return in_array($factKey, self::SENSITIVE_FACT_KEYS, true);
    }

    /**
     * @return array<string, array{question_label: string, section_key: string|null, section_label: string|null, question: IntakeQuestion|null}>
     */
    private function questionContext(Intake $intake): array
    {
        $context = [];

        $intake->loadMissing(['templateVersion.sections.questions.options']);

        foreach ($intake->templateVersion->sections as $section) {
            foreach ($section->questions as $question) {
                $context[$question->key] = [
                    'question_label' => (string) $question->label,
                    'section_key' => $section->key,
                    'section_label' => (string) $section->title,
                    'question' => $question,
                ];
            }
        }

        return $context;
    }

    /**
     * Per fotovraag + plek: tellingen inclusief afgekeurde foto’s (geen beeldbytes).
     *
     * @param  array<string, array{question_label: string, section_key: string|null, section_label: string|null, question: IntakeQuestion|null}>  $questions
     * @return list<array<string, mixed>>
     */
    private function photoQuestionStats(Intake $intake, array $questions): array
    {
        $supersessions = app(UploadSupersessionResolver::class)->resolve($intake);

        /** @var array<string, array{question_key: string, section_instance_key: string|null, uploads: list<IntakeUpload>, customer_continued_anyway: bool}> $groups */
        $groups = [];

        foreach ($intake->uploads as $upload) {
            if (! PhotoContinueAnywayAttention::isCurrentWizardUpload($upload, $supersessions)) {
                continue;
            }

            $key = $upload->question_key.'|'.(string) ($upload->section_instance_key ?? '');
            if (! isset($groups[$key])) {
                $groups[$key] = [
                    'question_key' => $upload->question_key,
                    'section_instance_key' => $upload->section_instance_key,
                    'uploads' => [],
                    'customer_continued_anyway' => false,
                ];
            }

            $groups[$key]['uploads'][] = $upload;
            $assessment = $upload->contentAssessment();
            if ($assessment instanceof PhotoContentAssessment && $assessment->customerAcceptedOverride()) {
                $groups[$key]['customer_continued_anyway'] = true;
            }
        }

        $stats = [];
        foreach ($groups as $group) {
            $meta = $questions[$group['question_key']] ?? null;
            $counts = PhotoContinueAnywayAttention::countStatuses($group['uploads']);
            // Geen `reference`: tellingen zijn context, geen citeerbaar bewijs.
            $stats[] = [
                'question_key' => $group['question_key'],
                'question_label' => $meta['question_label'] ?? $group['question_key'],
                'section_instance_key' => $group['section_instance_key'],
                'photo_count' => $counts['total'],
                'rejected_count' => $counts['rejected'],
                'not_assessed_count' => $counts['not_assessed'],
                'customer_continued_anyway' => $group['customer_continued_anyway'],
            ];
        }

        return $stats;
    }

    /**
     * @param  array<string, mixed>|null  $value
     * @return string|list<string>|null
     */
    private function optionLabelForAnswer(?IntakeQuestion $question, ?array $value): string|array|null
    {
        if (! $question instanceof IntakeQuestion || $value === null) {
            return null;
        }

        $question->loadMissing('options');

        if ($question->type === QuestionType::SingleChoice) {
            $raw = $value['value'] ?? null;
            if (! is_string($raw) || $raw === '') {
                return null;
            }
            $label = $question->options->firstWhere('value', $raw)?->label;

            return is_string($label) && $label !== '' ? $label : null;
        }

        if ($question->type === QuestionType::MultiChoice) {
            $rawValues = $value['values'] ?? null;
            if (! is_array($rawValues) || $rawValues === []) {
                return null;
            }
            $labels = [];
            foreach ($rawValues as $raw) {
                if (! is_string($raw) || $raw === '') {
                    continue;
                }
                $label = $question->options->firstWhere('value', $raw)?->label;
                if (is_string($label) && $label !== '') {
                    $labels[] = $label;
                }
            }

            return $labels === [] ? null : $labels;
        }

        return null;
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
                        ? $this->removeSensitiveReferences($segment->analysis)
                        : null,
                ];
            }

            $routes[] = [
                'reference' => $this->opaqueReference('route', $session->id),
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
        $isFollowUp = $upload->intake_follow_up_item_id !== null;

        return [
            'reference' => $isFollowUp
                ? $this->opaqueReference('follow_up_upload', $upload->id)
                : $this->questionReference($upload->question_key, $upload->section_instance_key).'@upload:'.$this->opaqueReference('upload', $upload->id),
            'question_key' => $isFollowUp ? 'follow_up_upload' : $upload->question_key,
            'question_label' => $question['question_label'] ?? null,
            'section_label' => $question['section_label'] ?? null,
            'section_instance_key' => $upload->section_instance_key,
            'context' => $upload->intake_follow_up_item_id === null ? 'intake' : 'follow_up',
            'mime_type' => $upload->mime_type,
            'size_bytes' => $upload->size_bytes,
            'usability_verdict' => $upload->usability_verdict?->value,
            'content_assessment' => $upload->contentAssessment()?->toArray(),
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
                    'reference' => 'round_'.$round->round_number.'@'.$this->opaqueReference('item', $item->id),
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

    private function questionReference(string $questionKey, ?string $sectionInstanceKey): string
    {
        return $sectionInstanceKey === null
            ? $questionKey
            : $questionKey.'@section:'.$sectionInstanceKey;
    }

    private function opaqueReference(string $type, int $id): string
    {
        return $type.'_'.substr(hash_hmac('sha256', (string) $id, (string) config('app.key')), 0, 16);
    }

    /**
     * @param  array<string, mixed>  $value
     * @return array<string, mixed>
     */
    private function removeSensitiveReferences(array $value): array
    {
        $blocked = [
            'address', 'address_line', 'bag_building_id', 'bag_id', 'bag_residence_id',
            'bbox', 'bounding_box', 'bounds', 'center', 'center_latitude', 'center_longitude',
            'centroid', 'city', 'coordinate', 'coordinates', 'disk', 'geometry', 'house_number',
            'href', 'id', 'identificatie', 'latitude', 'location', 'longitude', 'media_disk',
            'media_path', 'municipality', 'outline', 'pand_href', 'pand_id', 'path',
            'postal_code', 'postcode', 'province', 'rd_x', 'rd_y', 'source_reference',
            'source_url', 'street', 'url', 'verblijfsobject_id', 'x', 'y',
        ];
        $clean = [];

        foreach ($value as $key => $item) {
            $separatedKey = preg_replace('/[^A-Za-z0-9]+/', '_', (string) $key) ?? (string) $key;
            $normalizedKey = trim(Str::snake($separatedKey), '_');

            if (in_array($normalizedKey, $blocked, true)
                || str_starts_with($normalizedKey, 'bbox_')
                || str_starts_with($normalizedKey, 'coordinate_')
                || str_starts_with($normalizedKey, 'coordinates_')
                || str_ends_with($normalizedKey, '_href')
                || str_ends_with($normalizedKey, '_id')
                || str_ends_with($normalizedKey, '_ids')
                || str_ends_with($normalizedKey, '_url')
                || str_contains($normalizedKey, 'identificatie')) {
                continue;
            }

            $clean[$key] = is_array($item) ? $this->removeSensitiveReferences($item) : $item;
        }

        return $clean;
    }

    /**
     * Applies deterministic list/string limits and a hard serialized-size ceiling.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function boundedPayload(array $payload): array
    {
        $budget = self::INITIAL_VALUE_BUDGET;

        do {
            $remaining = $budget;
            $bounded = $this->boundValue($payload, $remaining);
            $encoded = json_encode($bounded, JSON_THROW_ON_ERROR);
            $budget = (int) floor($budget * 0.75);
        } while (strlen($encoded) > self::MAX_PAYLOAD_BYTES && $budget >= 1_000);

        if (! is_array($bounded) || strlen($encoded) > self::MAX_PAYLOAD_BYTES) {
            return ['truncated' => true];
        }

        /** @var array<string, mixed> $bounded */
        return $bounded;
    }

    private function boundValue(mixed $value, int &$remaining): mixed
    {
        if ($remaining <= 0) {
            return '[afgekapt: payloadlimiet bereikt]';
        }

        if (is_string($value)) {
            $length = min(mb_strlen($value), self::MAX_STRING_LENGTH, $remaining);
            $remaining -= $length;

            return mb_substr($value, 0, $length);
        }

        if (! is_array($value)) {
            $remaining -= 16;

            return $value;
        }

        $bounded = [];
        $items = 0;

        foreach ($value as $key => $item) {
            if ($items >= self::MAX_LIST_ITEMS || $remaining <= 0) {
                $bounded['truncated'] = true;
                break;
            }

            $bounded[$key] = $this->boundValue($item, $remaining);
            $items++;
        }

        return $bounded;
    }
}
