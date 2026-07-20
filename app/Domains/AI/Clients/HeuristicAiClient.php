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
        if (str_starts_with($request->promptVersion, 'attention_points')) {
            return $this->attentionPoints($request);
        }

        return $this->summary($request);
    }

    private function summary(AiCompletionRequest $request): AiCompletionResult
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
     * Deterministic attention points derived from the answers (BL-007). Each is a
     * *voorstel* the installer confirms; codes are stable so re-runs stay idempotent.
     */
    private function attentionPoints(AiCompletionRequest $request): AiCompletionResult
    {
        /** @var array<string, mixed> $answers */
        $answers = is_array($request->input['answers'] ?? null) ? $request->input['answers'] : [];

        $points = [];

        $freeGroup = $this->choice($answers, 'free_group_known');
        if ($freeGroup === 'no') {
            $points[] = ['code' => 'no_free_group', 'label' => 'Geen vrije groep bekend — controleer de meterkast/voeding.'];
        } elseif ($freeGroup === null || $freeGroup === 'unknown') {
            $points[] = ['code' => 'free_group_unknown', 'label' => 'Onbekend of er een vrije groep is — beoordeel de meterkastfoto.'];
        }

        if ($this->bool($answers, 'natural_fall_possible') === false) {
            $points[] = ['code' => 'condensate_pump_maybe', 'label' => 'Natuurlijk afschot lijkt niet mogelijk — mogelijk condenspomp nodig.'];
        }

        if (in_array($this->choice($answers, 'outdoor_accessibility'), ['scaffolding', 'restricted'], true)) {
            $points[] = ['code' => 'outdoor_access_difficult', 'label' => 'Buitenunitlocatie lijkt moeilijk bereikbaar — plan mogelijk hoogwerker/steiger.'];
        }

        if ($this->bool($answers, 'noise_sensitive') === true) {
            $points[] = ['code' => 'noise_sensitive_env', 'label' => 'Geluidsgevoelige omgeving (buren dichtbij) — let op plaatsing en geluid van de buitenunit.'];
        }

        if ($this->bool($answers, 'drillings_needed') === true) {
            $points[] = ['code' => 'drillings_needed', 'label' => 'Boringen door muren of vloeren zijn waarschijnlijk nodig.'];
        }

        if ($this->choice($answers, 'pipe_distance_indication') === 'long') {
            $points[] = ['code' => 'long_pipe_run', 'label' => 'Lange leidingafstand ingeschat — controleer capaciteit en materiaalkosten.'];
        }

        if ($this->choice($answers, 'building_type') === 'apartment' && $this->choice($answers, 'ownership') === 'rented') {
            $points[] = ['code' => 'permission_needed', 'label' => 'Huurappartement — mogelijk toestemming van VvE of verhuurder nodig.'];
        }

        return new AiCompletionResult(
            output: ['points' => $points],
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
