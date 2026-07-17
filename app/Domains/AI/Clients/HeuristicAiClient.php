<?php

declare(strict_types=1);

namespace App\Domains\AI\Clients;

use App\Domains\AI\Contracts\AiClientInterface;
use App\Domains\AI\DTOs\AiCompletionRequest;
use App\Domains\AI\DTOs\AiCompletionResult;

/**
 * Deterministic local summary without an external LLM.
 * Useful on cPanel/staging without API keys; clearly labeled as voorstel.
 */
final class HeuristicAiClient implements AiClientInterface
{
    public function complete(AiCompletionRequest $request): AiCompletionResult
    {
        /** @var array<string, mixed> $answers */
        $answers = is_array($request->input['answers'] ?? null) ? $request->input['answers'] : [];

        $reason = $this->text($answers, 'request_reason') ?? 'geen reden opgegeven';
        $cooling = $this->choice($answers, 'cooling_heating') ?? 'onbekend';
        $units = $this->number($answers, 'indoor_unit_count');
        $building = $this->choice($answers, 'building_type') ?? 'onbekend pandtype';
        $freeGroup = $this->choice($answers, 'free_group_known');
        $naturalFall = $this->bool($answers, 'natural_fall_possible');

        $unitText = $units === null ? 'een onbekend aantal' : (string) $units;
        $summary = "Aanvraag voor {$cooling} met ongeveer {$unitText} binnenunit(s) in een {$building}. "
            ."Aanleiding: {$reason}.";

        $highlights = [];

        if ($freeGroup === 'no') {
            $highlights[] = 'Geen vrije groep bekend — elektrische voeding verdient extra aandacht.';
        } elseif ($freeGroup === 'unknown') {
            $highlights[] = 'Onbekend of er een vrije groep beschikbaar is.';
        }

        if ($naturalFall === false) {
            $highlights[] = 'Natuurlijk afschot lijkt niet mogelijk; condenspomp mogelijk nodig.';
        }

        if ($highlights === []) {
            $highlights[] = 'Geen automatische aandachtspunten uit de antwoorden afgeleid.';
        }

        return new AiCompletionResult(
            output: [
                'summary' => $summary,
                'highlights' => $highlights,
            ],
            provider: 'heuristic',
            model: 'heuristic-v1',
        );
    }

    /**
     * @param  array<string, mixed>  $answers
     */
    private function text(array $answers, string $key): ?string
    {
        $value = $answers[$key]['text'] ?? null;

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * @param  array<string, mixed>  $answers
     */
    private function choice(array $answers, string $key): ?string
    {
        $value = $answers[$key]['value'] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @param  array<string, mixed>  $answers
     */
    private function number(array $answers, string $key): ?int
    {
        $value = $answers[$key]['number'] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * @param  array<string, mixed>  $answers
     */
    private function bool(array $answers, string $key): ?bool
    {
        $value = $answers[$key]['bool'] ?? null;

        return is_bool($value) ? $value : null;
    }
}
