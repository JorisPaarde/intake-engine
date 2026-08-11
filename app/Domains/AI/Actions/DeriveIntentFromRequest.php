<?php

declare(strict_types=1);

namespace App\Domains\AI\Actions;

use App\Domains\AI\Models\AiRun;
use App\Domains\AI\Services\LocalRequestIntentParser;
use App\Domains\Intake\Actions\SaveIntakeAnswer;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeActivityEvent;
use App\Domains\Intake\Models\IntakeAnswer;
use App\Enums\AiRunStatus;
use App\Enums\AiRunType;
use App\Enums\IntakeStatus;
use App\Enums\QuestionType;
use Illuminate\Support\Str;
use Throwable;

/**
 * Leidt uit bekende aanvraagcontext af welke templatevragen al beantwoord zijn.
 *
 * Primair (ADR-0013): met tekst-AI aan beoordeelt `PrefillAnswersFromKnownContext`
 * de volledige vraagenset van de gepinde template. Offline-fallback: een bevroren
 * lokale parser voor evidente koel-/ruimte-/zolderfeiten (BL-048).
 */
final class DeriveIntentFromRequest
{
    public const SOURCE_DERIVED = 'ai';

    public const SOURCE_SUGGESTED = 'ai_suggestion';

    public const SOURCE_REQUEST_TEXT = 'request_text';

    private const SOURCE_QUESTION = 'request_reason';

    public function __construct(
        private readonly LocalRequestIntentParser $localParser,
        private readonly SaveIntakeAnswer $saveIntakeAnswer,
        private readonly PrefillAnswersFromKnownContext $prefillFromKnownContext,
    ) {}

    public function handle(Intake $intake, bool $allowExternal = true): ?AiRun
    {
        if (! in_array($intake->status, [
            IntakeStatus::Draft,
            IntakeStatus::Sent,
            IntakeStatus::InProgress,
        ], true)) {
            return null;
        }

        $reason = $this->requestReason($intake);

        if ($reason === null) {
            return null;
        }

        if ($allowExternal && (bool) config('ai.text_inference.enabled', false)) {
            return $this->prefillFromKnownContext->handle($intake);
        }

        $localOutput = $this->localParser->parse($reason);

        if ($localOutput !== null) {
            return $this->recordLocalResult($intake, $reason, $localOutput);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $output
     */
    private function recordLocalResult(Intake $intake, string $reason, array $output): AiRun
    {
        $inputHash = hash('sha256', (string) json_encode([
            'parser_version' => LocalRequestIntentParser::VERSION,
            'request_reason' => $reason,
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
            'provider' => 'local',
            'model' => LocalRequestIntentParser::VERSION,
            'prompt_version' => LocalRequestIntentParser::VERSION,
            'input_hash' => $inputHash,
            'output' => null,
            'status' => AiRunStatus::Pending,
            'started_at' => now(),
        ]);

        try {
            $applied = $this->applyLocal(
                $intake,
                $output,
                self::SOURCE_REQUEST_TEXT,
            );
            $run->update([
                'output' => $output,
                'status' => AiRunStatus::Succeeded,
                'error_message' => null,
                'finished_at' => now(),
            ]);

            $run = $run->fresh() ?? $run;
            $this->recordActivity($intake, $run, $output, $applied);

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

    /**
     * @param  array<string, mixed>  $output
     * @param  list<string>  $applied
     */
    private function recordActivity(Intake $intake, AiRun $run, array $output, array $applied): void
    {
        IntakeActivityEvent::query()->create([
            'intake_id' => $intake->id,
            'actor_type' => 'system',
            'actor_id' => null,
            'event' => 'request_intent_derived',
            'properties' => [
                'ai_run_id' => $run->id,
                'provider' => $run->provider,
                'confidence' => $output['confidence'],
                'question_keys' => $applied,
            ],
            'created_at' => now(),
        ]);
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

        return mb_strlen($text) >= 10 ? $text : null;
    }

    /**
     * @param  array<string, mixed>  $output
     * @return list<string>
     */
    private function applyLocal(
        Intake $intake,
        array $output,
        string $source,
    ): array {
        $confidence = (string) $output['confidence'];

        if ($confidence === 'low') {
            return [];
        }

        $applied = [];

        if ($output['cooling_heating'] !== 'unknown'
            && $this->mayWrite($intake, 'cooling_heating', null)) {
            $this->saveIntakeAnswer->handle($intake, 'cooling_heating', null, ['value' => $output['cooling_heating']], $source);
            $applied[] = 'cooling_heating';
        }

        /** @var list<string> $rooms */
        $rooms = array_values($output['rooms']);
        $floorLevel = $output['floor_level'] ?? null;
        $floorLevelAnswer = $floorLevel === 'attic'
            ? $this->floorLevelAnswer($intake, $floorLevel)
            : null;

        if ($rooms === []) {
            return $applied;
        }

        if ($this->mayWrite($intake, 'indoor_unit_count', null)) {
            $this->saveIntakeAnswer->handle($intake, 'indoor_unit_count', null, ['number' => count($rooms)], $source);
            $applied[] = 'indoor_unit_count';
        }

        foreach ($rooms as $index => $roomType) {
            $instanceKey = 'room-'.($index + 1);

            if ($this->mayWrite($intake, 'room_type', $instanceKey)) {
                $this->saveIntakeAnswer->handle($intake, 'room_type', $instanceKey, ['value' => $roomType], $source);
                $applied[] = 'room_type@'.$instanceKey;
            }

            if ($floorLevelAnswer !== null
                && $this->mayWrite($intake, 'floor_level', $instanceKey)) {
                $this->saveIntakeAnswer->handle($intake, 'floor_level', $instanceKey, $floorLevelAnswer, $source);
                $applied[] = 'floor_level@'.$instanceKey;
            }
        }

        return $applied;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function floorLevelAnswer(Intake $intake, string $floorLevel): ?array
    {
        $intake->loadMissing('templateVersion.sections.questions');

        foreach ($intake->templateVersion->sections as $section) {
            foreach ($section->questions as $question) {
                if ($question->key !== 'floor_level') {
                    continue;
                }

                if ($question->type === QuestionType::SingleChoice) {
                    $optionExists = $question->options()
                        ->where('value', $floorLevel)
                        ->exists();

                    return $optionExists ? ['value' => $floorLevel] : null;
                }

                if (in_array($question->type, [QuestionType::ShortText, QuestionType::LongText], true)) {
                    return ['text' => 'Zolder'];
                }

                return null;
            }
        }

        return null;
    }

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

        return in_array($existing->prefill_source, [
            self::SOURCE_DERIVED,
            self::SOURCE_SUGGESTED,
            self::SOURCE_REQUEST_TEXT,
        ], true);
    }
}
