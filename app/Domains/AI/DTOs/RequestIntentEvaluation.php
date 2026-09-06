<?php

declare(strict_types=1);

namespace App\Domains\AI\DTOs;

/**
 * Pure evaluatie-uitkomst van openingszin → lokale parser → catalogus-AI.
 * Geen side effects; productie past dit toe, de dev-preview toont het.
 */
final readonly class RequestIntentEvaluation
{
    /**
     * @param  array{
     *     template_key: string,
     *     template_version: int,
     *     parser_version: string,
     *     prompt_version: string|null,
     *     provider: string|null,
     *     model: string|null
     * }  $versions
     * @param  array<string, mixed>|null  $localOutput
     * @param  list<RequestPrefillCandidate>  $candidates
     * @param  array<string, array<string, mixed>>  $hypotheticalAnswers  composite key => value
     * @param  list<array{question_key: string, section_instance_key: string|null, label: string}>  $openQuestions
     */
    public function __construct(
        public array $versions,
        public bool $textInferenceEnabled,
        public ?string $textInferenceGateReason,
        public ?array $localOutput,
        public array $candidates,
        public array $hypotheticalAnswers,
        public array $openQuestions,
        public ?string $aiEvidence = null,
        public ?string $aiError = null,
        public bool $aiAttempted = false,
    ) {}

    /**
     * @return list<RequestPrefillCandidate>
     */
    public function fills(): array
    {
        return array_values(array_filter(
            $this->candidates,
            static fn (RequestPrefillCandidate $c): bool => $c->disposition === RequestPrefillCandidate::DISPOSITION_FILL,
        ));
    }

    /**
     * @return list<RequestPrefillCandidate>
     */
    public function suggestions(): array
    {
        return array_values(array_filter(
            $this->candidates,
            static fn (RequestPrefillCandidate $c): bool => $c->disposition === RequestPrefillCandidate::DISPOSITION_SUGGESTION,
        ));
    }

    /**
     * @return list<RequestPrefillCandidate>
     */
    public function rejected(): array
    {
        return array_values(array_filter(
            $this->candidates,
            static fn (RequestPrefillCandidate $c): bool => $c->disposition === RequestPrefillCandidate::DISPOSITION_REJECTED,
        ));
    }

    /**
     * Geclassificeerde uitkomsten die productie zou schrijven (fill + suggestion).
     *
     * @return array<string, array{disposition: string, value: array<string, mixed>, source: string, confidence: string|null}>
     */
    public function classifiedWritable(): array
    {
        $out = [];

        foreach ($this->candidates as $candidate) {
            if (! in_array($candidate->disposition, [
                RequestPrefillCandidate::DISPOSITION_FILL,
                RequestPrefillCandidate::DISPOSITION_SUGGESTION,
            ], true)) {
                continue;
            }

            if ($candidate->value === null) {
                continue;
            }

            $out[$candidate->compositeKey()] = [
                'disposition' => $candidate->disposition,
                'value' => $candidate->value,
                'source' => $candidate->source,
                'confidence' => $candidate->confidence,
            ];
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'versions' => $this->versions,
            'text_inference_enabled' => $this->textInferenceEnabled,
            'text_inference_gate_reason' => $this->textInferenceGateReason,
            'local_output' => $this->localOutput,
            'candidates' => array_map(
                static fn (RequestPrefillCandidate $c): array => $c->toArray(),
                $this->candidates,
            ),
            'hypothetical_answers' => $this->hypotheticalAnswers,
            'open_questions' => $this->openQuestions,
            'ai_evidence' => $this->aiEvidence,
            'ai_error' => $this->aiError,
            'ai_attempted' => $this->aiAttempted,
        ];
    }
}
