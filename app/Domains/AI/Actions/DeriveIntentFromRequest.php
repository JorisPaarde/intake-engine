<?php

declare(strict_types=1);

namespace App\Domains\AI\Actions;

use App\Domains\AI\Models\AiRun;
use App\Domains\AI\Services\AiGateway;
use App\Domains\AI\Services\PromptVersionRepository;
use App\Domains\Intake\Actions\SaveIntakeAnswer;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeActivityEvent;
use App\Domains\Intake\Models\IntakeAnswer;
use App\Enums\AiRunStatus;
use App\Enums\AiRunType;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Leidt uit de openingsvraag af wat de aanvrager daar al heeft verteld.
 *
 * "Slaapkamer en woonkamer worden te warm in de zomer" beantwoordt drie vragen die de
 * wizard daarna nog stelde: wat er moet gebeuren (koelen), hoeveel binnenunits (twee) en
 * om welke ruimtes het gaat (slaapkamer, woonkamer). Dat opnieuw vragen is precies wat het
 * ontwerpprincipe verbiedt.
 *
 * Zekerheid werkt zoals bij de foto-afleiding: `high` laat de vraag vervallen, `medium`
 * levert een bevestigbare voorzet, `low` doet niets. De prompt mag alleen `high` kiezen
 * wanneer de aanvrager de ruimtes expliciet noemt — "het is warm boven" is geen opdracht
 * voor twee units.
 */
final class DeriveIntentFromRequest
{
    public const SOURCE_DERIVED = 'ai';

    public const SOURCE_SUGGESTED = 'ai_suggestion';

    private const SOURCE_QUESTION = 'request_reason';

    /** Dezelfde bovengrens als de repeatable ruimtesectie aanhoudt. */
    private const MAX_ROOMS = 8;

    public function __construct(
        private readonly AiGateway $aiGateway,
        private readonly PromptVersionRepository $promptVersions,
        private readonly SaveIntakeAnswer $saveIntakeAnswer,
    ) {}

    public function handle(Intake $intake): ?AiRun
    {
        if (! (bool) config('ai.text_inference.enabled', false)) {
            return null;
        }

        $reason = $this->requestReason($intake);

        if ($reason === null) {
            return null;
        }

        $promptName = (string) config('ai.request_intent_prompt', 'request_intent');
        $promptVersion = $this->promptVersions->version($promptName);
        $promptBody = $this->promptVersions->body($promptName);

        $input = ['task' => 'derive_request_intent', 'request_reason' => $reason];
        $inputHash = hash('sha256', (string) json_encode([
            'prompt_version' => $promptVersion,
            'input' => $input,
        ], JSON_THROW_ON_ERROR));

        $existing = AiRun::query()
            ->where('intake_id', $intake->id)
            ->where('type', AiRunType::RequestIntent)
            ->where('input_hash', $inputHash)
            ->where('status', AiRunStatus::Succeeded)
            ->latest('id')
            ->first();

        if ($existing instanceof AiRun) {
            return $existing;
        }

        $run = AiRun::query()->create([
            'intake_id' => $intake->id,
            'type' => AiRunType::RequestIntent,
            'provider' => (string) config('ai.provider', 'null'),
            'model' => null,
            'prompt_version' => $promptVersion,
            'input_hash' => $inputHash,
            'output' => null,
            'status' => AiRunStatus::Pending,
            'started_at' => now(),
        ]);

        try {
            $result = $this->aiGateway->complete(
                prompt: $promptBody,
                input: $input,
                promptVersion: $promptVersion,
            );

            $output = $this->validateOutput($result->output);

            $run->update([
                'status' => AiRunStatus::Succeeded,
                'provider' => $result->provider,
                'model' => $result->model,
                'output' => $output,
                'error_message' => null,
                'finished_at' => now(),
            ]);

            $run = $run->fresh() ?? $run;
            $applied = $this->apply($intake, $output);

            IntakeActivityEvent::query()->create([
                'intake_id' => $intake->id,
                'actor_type' => 'system',
                'actor_id' => null,
                'event' => 'request_intent_derived',
                // Sleutels en zekerheid, nooit de vrije tekst zelf (ADR-0002).
                'properties' => [
                    'ai_run_id' => $run->id,
                    'confidence' => $output['confidence'],
                    'question_keys' => $applied,
                ],
                'created_at' => now(),
            ]);

            return $run;
        } catch (Throwable $exception) {
            $run->update([
                'status' => AiRunStatus::Failed,
                'error_message' => Str::limit($exception->getMessage(), 1000, ''),
                'finished_at' => now(),
            ]);

            return $run->fresh() ?? $run;
        }
    }

    private function requestReason(Intake $intake): ?string
    {
        $answer = IntakeAnswer::query()
            ->where('intake_id', $intake->id)
            ->where('question_key', self::SOURCE_QUESTION)
            ->whereNull('section_instance_key')
            ->first();

        $text = is_array($answer?->value) ? ($answer->value['text'] ?? null) : null;

        if (! is_string($text)) {
            return null;
        }

        $text = trim($text);

        // Onder een paar woorden valt er niets te concluderen.
        return mb_strlen($text) >= 10 ? $text : null;
    }

    /**
     * @param  array<string, mixed>  $output
     * @return array<string, mixed>
     */
    private function validateOutput(array $output): array
    {
        $validator = Validator::make($output, [
            'cooling_heating' => ['required', Rule::in(['cooling', 'heating', 'both', 'unknown'])],
            'rooms' => ['present', 'array', 'max:'.self::MAX_ROOMS],
            'rooms.*' => [Rule::in(['living_room', 'bedroom', 'office', 'attic', 'other'])],
            'confidence' => ['required', Rule::in(['high', 'medium', 'low'])],
            'evidence' => ['required', 'string', 'min:3', 'max:300'],
        ]);

        if ($validator->fails()) {
            throw ValidationException::withMessages($validator->errors()->toArray());
        }

        /** @var array<string, mixed> $validated */
        $validated = $validator->validated();

        return $validated;
    }

    /**
     * @param  array<string, mixed>  $output
     * @return list<string>
     */
    private function apply(Intake $intake, array $output): array
    {
        $confidence = (string) $output['confidence'];

        if ($confidence === 'low') {
            return [];
        }

        $source = $confidence === 'high' ? self::SOURCE_DERIVED : self::SOURCE_SUGGESTED;
        $applied = [];

        if ($output['cooling_heating'] !== 'unknown'
            && $this->mayWrite($intake, 'cooling_heating', null)) {
            $this->saveIntakeAnswer->handle($intake, 'cooling_heating', null, ['value' => $output['cooling_heating']], $source);
            $applied[] = 'cooling_heating';
        }

        /** @var list<string> $rooms */
        $rooms = array_values($output['rooms']);

        if ($rooms === []) {
            return $applied;
        }

        if ($this->mayWrite($intake, 'indoor_unit_count', null)) {
            $this->saveIntakeAnswer->handle($intake, 'indoor_unit_count', null, ['number' => count($rooms)], $source);
            $applied[] = 'indoor_unit_count';
        }

        // De ruimtesectie herhaalt op volgorde, dus de zoveelste genoemde ruimte hoort bij
        // room-N. Alleen invullen zolang de aanvrager daar zelf nog niets heeft gezet.
        foreach ($rooms as $index => $roomType) {
            $instanceKey = 'room-'.($index + 1);

            if (! $this->mayWrite($intake, 'room_type', $instanceKey)) {
                continue;
            }

            $this->saveIntakeAnswer->handle($intake, 'room_type', $instanceKey, ['value' => $roomType], $source);
            $applied[] = 'room_type@'.$instanceKey;
        }

        return $applied;
    }

    /**
     * Een antwoord dat de aanvrager of installateur zelf gaf wint altijd.
     */
    private function mayWrite(Intake $intake, string $questionKey, ?string $sectionInstanceKey): bool
    {
        $existing = IntakeAnswer::query()
            ->where('intake_id', $intake->id)
            ->where('question_key', $questionKey)
            ->when(
                $sectionInstanceKey === null,
                static fn ($query) => $query->whereNull('section_instance_key'),
                static fn ($query) => $query->where('section_instance_key', $sectionInstanceKey),
            )
            ->first();

        if (! $existing instanceof IntakeAnswer) {
            return true;
        }

        return in_array($existing->prefill_source, [self::SOURCE_DERIVED, self::SOURCE_SUGGESTED], true);
    }
}
