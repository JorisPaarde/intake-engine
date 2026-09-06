<?php

declare(strict_types=1);

namespace App\Domains\AI\Services;

use App\Domains\AI\DTOs\RequestIntentEvaluation;
use App\Domains\AI\DTOs\RequestPrefillCandidate;
use App\Domains\AI\Exceptions\AiClientException;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeTemplate;
use App\Domains\Intake\Models\IntakeTemplateVersion;
use App\Domains\Intake\Services\IntakeStepBuilder;
use App\Domains\Intake\Services\VisibilityResolver;
use App\Enums\IntakeStatus;
use App\Enums\QuestionType;
use App\Enums\TemplateVersionStatus;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * Pure evaluatielaag voor openingszin-prefill (BL-104).
 * Productie en Dev-admin gebruiken dezelfde keten; alleen productie past toe.
 */
final class EvaluateRequestIntent
{
    public function __construct(
        private readonly LocalRequestIntentParser $localParser,
        private readonly TemplateQuestionCatalogBuilder $catalogBuilder,
        private readonly RequestPrefillContextBuilder $contextBuilder,
        private readonly RequestPrefillOutcomeClassifier $classifier,
        private readonly AiGateway $aiGateway,
        private readonly PromptVersionRepository $promptVersions,
        private readonly IntakeStepBuilder $stepBuilder,
    ) {}

    /**
     * Dry-run preview: zelfde keten als Nieuwe opname, zonder duurzame side effects.
     */
    public function preview(string $requestReason, string $templateKey = 'airco'): RequestIntentEvaluation
    {
        $version = $this->resolvePublishedVersion($templateKey);
        $catalog = $this->catalogBuilder->fromVersion($version);
        $reason = trim($requestReason);

        $context = [
            'request_reason' => $reason,
            'answers' => [[
                'question_key' => 'request_reason',
                'section_instance_key' => null,
                'prefill_source' => 'installer',
                'value' => ['text' => $reason],
            ]],
            'external_facts' => [],
            'installer_observations' => [],
        ];

        return $this->evaluate(
            reason: $reason,
            catalog: $catalog,
            context: $context,
            version: $version,
            allowExternal: true,
            intake: null,
        );
    }

    /**
     * Evaluatie tegen een bestaande intake (zelfde classificatie als productie-apply).
     */
    public function forIntake(Intake $intake, bool $allowExternal = true): RequestIntentEvaluation
    {
        if (! in_array($intake->status, [
            IntakeStatus::Draft,
            IntakeStatus::Sent,
            IntakeStatus::InProgress,
        ], true)) {
            $version = $intake->templateVersion()->with(['template', 'sections.questions.options'])->firstOrFail();
            $catalog = $this->catalogBuilder->fromVersion($version);

            return $this->emptyEvaluation(
                catalog: $catalog,
                version: $version,
                gateReason: 'Opnamestatus staat geen prefill toe.',
                reason: null,
            );
        }

        $version = $intake->templateVersion()
            ->with(['template', 'sections.questions.options', 'sections.questions.rules'])
            ->firstOrFail();
        $catalog = $this->catalogBuilder->fromVersion($version);
        $context = $this->contextBuilder->build($intake);
        $reason = $context['request_reason'];
        $reason = is_string($reason) ? trim($reason) : null;

        return $this->evaluate(
            reason: $reason,
            catalog: $catalog,
            context: $context,
            version: $version,
            allowExternal: $allowExternal,
            intake: $intake,
        );
    }

    /**
     * @param  array<string, mixed>  $catalog
     * @param  array{
     *     request_reason: string|null,
     *     answers: list<array<string, mixed>>,
     *     external_facts: list<array<string, mixed>>,
     *     installer_observations: list<array<string, mixed>>
     * }  $context
     */
    private function evaluate(
        ?string $reason,
        array $catalog,
        array $context,
        IntakeTemplateVersion $version,
        bool $allowExternal,
        ?Intake $intake,
    ): RequestIntentEvaluation {
        $promptName = (string) config('ai.request_prefill_prompt', 'request_prefill');
        $promptVersion = null;
        try {
            $promptVersion = $this->promptVersions->version($promptName);
        } catch (Throwable) {
            $promptVersion = null;
        }

        $versions = [
            'template_key' => (string) ($catalog['template_key'] ?? $version->template->key ?? 'airco'),
            'template_version' => (int) ($catalog['template_version'] ?? $version->version),
            'parser_version' => LocalRequestIntentParser::VERSION,
            'prompt_version' => $promptVersion,
            'provider' => null,
            'model' => null,
        ];

        if ($reason === null || mb_strlen($reason) < 10) {
            return $this->emptyEvaluation(
                catalog: $catalog,
                version: $version,
                gateReason: null,
                reason: $reason,
                versions: $versions,
                message: 'Openingszin ontbreekt of is te kort (minimaal 10 tekens).',
            );
        }

        $localOutput = $this->localParser->parse($reason);
        $localCandidates = $localOutput === null
            ? []
            : $this->classifier->classifyLocalOutput($localOutput, $catalog);

        $textEnabled = (bool) config('ai.text_inference.enabled', false);
        $gateReason = null;
        $aiCandidates = [];
        $aiEvidence = null;
        $aiError = null;
        $aiAttempted = false;

        if (! $allowExternal) {
            $gateReason = 'Externe tekst-AI is voor deze aanroep uitgeschakeld (alleen lokaal pad).';
        } elseif (! $textEnabled) {
            $gateReason = 'Tekst-AI staat uit (AI_TEXT_INFERENCE_ENABLED=false) — alleen het lokale pad is uitgevoerd. Geen stille mock.';
        } else {
            $aiAttempted = true;
            try {
                $promptBody = $this->promptVersions->body($promptName);
                $input = [
                    'task' => 'prefill_from_known_context',
                    'known_context' => $context,
                    'question_catalog' => $catalog,
                ];

                $result = $this->aiGateway->complete(
                    prompt: $promptBody,
                    input: $input,
                    promptVersion: (string) $promptVersion,
                );

                $versions['provider'] = $result->provider;
                $versions['model'] = $result->model;

                $classified = $this->classifier->classifyCatalogOutput(
                    $result->output,
                    $catalog,
                    $this->photoKeys($version),
                );
                $aiEvidence = $classified['evidence'];
                $aiCandidates = $classified['candidates'];
            } catch (AiClientException $exception) {
                $versions['provider'] = (string) config('ai.provider', 'null');
                $versions['model'] = null;
                $aiError = 'Catalogus-AI mislukt: '.$this->safeErrorMessage($exception);
            } catch (ValidationException $exception) {
                $versions['provider'] = (string) config('ai.provider', 'null');
                $aiError = 'Catalogus-AI gaf ongeldige output (validatie).';
            } catch (Throwable $exception) {
                $versions['provider'] = (string) config('ai.provider', 'null');
                $aiError = 'Catalogus-AI mislukt: '.$this->safeErrorMessage($exception);
            }
        }

        $candidates = $this->mergeCandidates($localCandidates, $aiCandidates);
        $hypothetical = $this->hypotheticalAnswers($candidates);
        $openQuestions = $this->openQuestions($version, $hypothetical, $intake);

        return new RequestIntentEvaluation(
            versions: $versions,
            textInferenceEnabled: $textEnabled && $allowExternal,
            textInferenceGateReason: $gateReason,
            localOutput: $localOutput,
            candidates: $candidates,
            hypotheticalAnswers: $hypothetical,
            openQuestions: $openQuestions,
            aiEvidence: $aiEvidence,
            aiError: $aiError,
            aiAttempted: $aiAttempted,
        );
    }

    /**
     * @param  list<RequestPrefillCandidate>  $local
     * @param  list<RequestPrefillCandidate>  $ai
     * @return list<RequestPrefillCandidate>
     */
    private function mergeCandidates(array $local, array $ai): array
    {
        // Productie: lokaal eerst, AI mag AI-/request_text-bronnen overschrijven.
        // Voor classificatie: AI-writable/rejected vervangt lokale writable op dezelfde key.
        $merged = [];

        foreach ($local as $candidate) {
            $merged[$candidate->compositeKey()] = $candidate;
        }

        foreach ($ai as $candidate) {
            $key = $candidate->compositeKey();

            if (in_array($candidate->disposition, [
                RequestPrefillCandidate::DISPOSITION_FILL,
                RequestPrefillCandidate::DISPOSITION_SUGGESTION,
            ], true)) {
                $merged[$key] = $candidate;

                continue;
            }

            // Afwijzing alleen tonen als er nog geen lokale fill op die key staat.
            if (! isset($merged[$key])
                || $merged[$key]->disposition === RequestPrefillCandidate::DISPOSITION_REJECTED) {
                $merged[$key] = $candidate;
            } else {
                // Bewaar afwijzing als apart diagnostisch item met unieke sleutel.
                $merged[$key.'#rejected'] = $candidate;
            }
        }

        return array_values($merged);
    }

    /**
     * @param  list<RequestPrefillCandidate>  $candidates
     * @return array<string, array<string, mixed>>
     */
    private function hypotheticalAnswers(array $candidates): array
    {
        $answers = [];

        foreach ($candidates as $candidate) {
            if (! in_array($candidate->disposition, [
                RequestPrefillCandidate::DISPOSITION_FILL,
                RequestPrefillCandidate::DISPOSITION_SUGGESTION,
            ], true)) {
                continue;
            }

            if ($candidate->value === null) {
                continue;
            }

            $composite = VisibilityResolver::compositeKey(
                $candidate->questionKey,
                $candidate->sectionInstanceKey,
            );
            $answers[$composite] = $candidate->value;
        }

        return $answers;
    }

    /**
     * @param  array<string, array<string, mixed>>  $liveAnswers
     * @return list<array{question_key: string, section_instance_key: string|null, label: string}>
     */
    private function openQuestions(
        IntakeTemplateVersion $version,
        array $liveAnswers,
        ?Intake $intake,
    ): array {
        $version->loadMissing(['sections.questions.options', 'sections.questions.rules', 'template']);

        $probe = $intake ?? new Intake([
            'intake_template_version_id' => $version->id,
            'status' => IntakeStatus::Draft,
        ]);
        $probe->setRelation('templateVersion', $version);
        $probe->setRelation('answers', new Collection);

        $steps = $this->stepBuilder->build($probe, $version, $liveAnswers);
        $open = [];

        foreach ($steps as $step) {
            $composite = VisibilityResolver::compositeKey(
                $step['question_key'],
                $step['section_instance_key'],
            );

            if (array_key_exists($composite, $liveAnswers)) {
                continue;
            }

            if ($step['question_key'] === 'request_reason') {
                continue;
            }

            $open[] = [
                'question_key' => $step['question_key'],
                'section_instance_key' => $step['section_instance_key'],
                'label' => (string) $step['title'],
            ];
        }

        return $open;
    }

    /**
     * @param  array<string, mixed>  $catalog
     * @param  array<string, mixed>|null  $versions
     */
    private function emptyEvaluation(
        array $catalog,
        IntakeTemplateVersion $version,
        ?string $gateReason,
        ?string $reason,
        ?array $versions = null,
        ?string $message = null,
    ): RequestIntentEvaluation {
        $promptName = (string) config('ai.request_prefill_prompt', 'request_prefill');
        $promptVersion = null;
        try {
            $promptVersion = $this->promptVersions->version($promptName);
        } catch (Throwable) {
            $promptVersion = null;
        }

        $versions ??= [
            'template_key' => (string) ($catalog['template_key'] ?? $version->template->key ?? 'airco'),
            'template_version' => (int) ($catalog['template_version'] ?? $version->version),
            'parser_version' => LocalRequestIntentParser::VERSION,
            'prompt_version' => $promptVersion,
            'provider' => null,
            'model' => null,
        ];

        $candidates = [];
        if ($message !== null) {
            $candidates[] = new RequestPrefillCandidate(
                questionKey: '_input',
                sectionInstanceKey: null,
                label: 'Invoer',
                value: null,
                confidence: null,
                evidence: null,
                disposition: RequestPrefillCandidate::DISPOSITION_REJECTED,
                source: RequestPrefillCandidate::SOURCE_LOCAL,
                reason: $message,
            );
        }

        return new RequestIntentEvaluation(
            versions: $versions,
            textInferenceEnabled: (bool) config('ai.text_inference.enabled', false),
            textInferenceGateReason: $gateReason,
            localOutput: null,
            candidates: $candidates,
            hypotheticalAnswers: [],
            openQuestions: $this->openQuestions($version, [], null),
            aiEvidence: null,
            aiError: null,
            aiAttempted: false,
        );
    }

    /**
     * @return list<string>
     */
    private function photoKeys(IntakeTemplateVersion $version): array
    {
        $version->loadMissing('sections.questions');
        $keys = [];

        foreach ($version->sections as $section) {
            foreach ($section->questions as $question) {
                if ($question->type === QuestionType::Photo) {
                    $keys[] = $question->key;
                }
            }
        }

        return array_values(array_unique($keys));
    }

    private function resolvePublishedVersion(string $templateKey): IntakeTemplateVersion
    {
        $template = IntakeTemplate::query()
            ->where('key', $templateKey)
            ->where('is_active', true)
            ->first();

        if ($template === null) {
            throw new RuntimeException("Geen actieve template [{$templateKey}].");
        }

        $version = IntakeTemplateVersion::query()
            ->where('intake_template_id', $template->id)
            ->where('status', TemplateVersionStatus::Published)
            ->orderByDesc('version')
            ->with(['template', 'sections.questions.options', 'sections.questions.rules'])
            ->first();

        if ($version === null) {
            throw new RuntimeException("Template [{$templateKey}] heeft geen gepubliceerde versie.");
        }

        $version->setRelation('template', $template);

        return $version;
    }

    private function safeErrorMessage(Throwable $exception): string
    {
        $message = $exception->getMessage();

        // Nooit secrets of lange free-text lekken in de UI.
        $message = (string) preg_replace('/sk-[A-Za-z0-9_-]+/', '[redacted]', $message);
        $message = (string) preg_replace('/Bearer\s+\S+/i', 'Bearer [redacted]', $message);

        return mb_substr($message, 0, 240);
    }
}
