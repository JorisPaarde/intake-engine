<?php

declare(strict_types=1);

namespace App\Domains\AI\Eval;

use App\Domains\AI\Services\AiGateway;
use Throwable;

/**
 * Eval-only judge on meaning (N90): asks a cheap model whether a text makes a
 * given statement as a fact/decision. Never used in product code; replaces
 * keyword lists for claims where negation or "nog niet besloten" matters.
 */
final class EvalSemanticJudge
{
    public const PROMPT_VERSION = 'eval-judge-v1';

    private const PROMPT = <<<'TXT'
Je bent een strenge beoordelaar voor een evaluatie. Je krijgt een `question` en een lijst `texts` (zinnen uit een rapport).
Beantwoord de vraag letterlijk en op betekenis, niet op losse woorden.
- "yes": minstens één tekst doet de beschreven uitspraak.
- "no": geen tekst doet dat. Een ontkenning ("niet", "niet meer", "nog niet besloten", "nog te bepalen") betekent dat de uitspraak NIET wordt gedaan. Een vraag of controlepunt ("controleer of …") doet de uitspraak niet. Of voorzichtige formuleringen of voorkeuren meetellen, staat in de vraag.
- "unclear": alleen als de tekst echt dubbelzinnig is.
Geef `quote`: de letterlijke tekst die je "yes" gaf, anders een lege string.
Antwoord uitsluitend met JSON.
TXT;

    public function __construct(
        private readonly AiGateway $aiGateway,
    ) {}

    /**
     * @param  list<string>  $texts
     * @return array{verdict: 'yes'|'no'|'unclear'|null, quote: string, error: string|null, estimated_cost: string|null, total_tokens: int|null}
     */
    public function judge(array $texts, string $question): array
    {
        $texts = array_values(array_filter($texts, static fn (string $t): bool => trim($t) !== ''));
        if ($texts === []) {
            return ['verdict' => 'no', 'quote' => '', 'error' => null, 'estimated_cost' => null, 'total_tokens' => null];
        }
        try {
            $result = $this->aiGateway->complete(
                prompt: self::PROMPT,
                input: ['question' => $question, 'texts' => $texts],
                promptVersion: self::PROMPT_VERSION,
                model: (string) (config('ai.eval_judge_model') ?: config('ai.model')),
                temperature: 0.0,
                responseSchema: [
                    'name' => 'eval_judge',
                    'schema' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['verdict', 'quote'],
                        'properties' => [
                            'verdict' => ['type' => 'string', 'enum' => ['yes', 'no', 'unclear']],
                            'quote' => ['type' => 'string'],
                        ],
                    ],
                ],
                timeoutSeconds: 30,
            );
        } catch (Throwable $e) {
            return ['verdict' => null, 'quote' => '', 'error' => $e->getMessage(), 'estimated_cost' => null, 'total_tokens' => null];
        }

        $verdict = $result->output['verdict'] ?? null;

        return [
            'verdict' => in_array($verdict, ['yes', 'no', 'unclear'], true) ? $verdict : null,
            'quote' => is_string($result->output['quote'] ?? null) ? $result->output['quote'] : '',
            'error' => null,
            'estimated_cost' => $result->estimatedCost,
            'total_tokens' => $result->totalTokens,
        ];
    }
}
