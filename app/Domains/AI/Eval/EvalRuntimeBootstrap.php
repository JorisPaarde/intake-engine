<?php

declare(strict_types=1);

namespace App\Domains\AI\Eval;

use App\Domains\AI\Clients\FakeAiClient;

/**
 * Zet AI-config klaar voor eval:interpretation vóór AiGateway/AiClient resolve.
 *
 * Echte run: alleen AI_API_KEY in de omgeving is genoeg; bestaande prod-model/
 * base_url/budget uit config/.env blijven leidend. Ontbrekende of inerte
 * defaults (provider null, openai.com, gpt-4o-mini, geen budget) worden
 * aangevuld zoals in de eval-workflow (OpenRouter + gemini-3.1-flash-lite).
 */
final class EvalRuntimeBootstrap
{
    public const string EVAL_OPENROUTER_BASE_URL = 'https://openrouter.ai/api/v1';

    public const string EVAL_DEFAULT_MODEL = 'google/gemini-3.1-flash-lite';

    public const int EVAL_DEFAULT_DAILY_BUDGET_CENTS = 200;

    /**
     * @return array{
     *     mode: 'fake'|'openai',
     *     api_key_present: bool,
     *     applied: list<string>,
     *     warnings: list<string>
     * }
     */
    public function activate(bool $forceFake): array
    {
        $key = $this->resolveApiKey();
        $keyPresent = $key !== null;

        if ($forceFake || ! $keyPresent) {
            $this->activateFake();

            return [
                'mode' => 'fake',
                'api_key_present' => $keyPresent,
                'applied' => ['provider=fake', 'inference flags on'],
                'warnings' => [],
            ];
        }

        $real = $this->activateReal($key);

        return [
            'mode' => 'openai',
            'api_key_present' => true,
            'applied' => $real['applied'],
            'warnings' => $real['warnings'],
        ];
    }

    public function activateFake(): void
    {
        config([
            'ai.provider' => 'fake',
            'ai.text_inference.enabled' => true,
            'ai.photo_inference.enabled' => true,
            'ai.dossier.enabled' => true,
        ]);
        FakeAiClient::reset();
    }

    /**
     * @return array{applied: list<string>, warnings: list<string>}
     */
    public function activateReal(string $apiKey): array
    {
        $applied = ['api_key from env'];
        $warnings = [];

        config(['ai.api_key' => $apiKey]);

        $provider = strtolower(trim((string) config('ai.provider', 'null')));
        if (in_array($provider, ['null', '', 'fake', 'heuristic'], true)) {
            config(['ai.provider' => 'openai']);
            $applied[] = 'provider=openai';
        }

        $baseUrl = rtrim((string) config('ai.base_url', ''), '/');
        if ($baseUrl === '' || $baseUrl === 'https://api.openai.com/v1') {
            config(['ai.base_url' => self::EVAL_OPENROUTER_BASE_URL]);
            $applied[] = 'base_url='.self::EVAL_OPENROUTER_BASE_URL;
        }

        $model = trim((string) config('ai.model', ''));
        if ($model === '' || $model === 'gpt-4o-mini') {
            config([
                'ai.model' => self::EVAL_DEFAULT_MODEL,
                'ai.vision_model' => self::EVAL_DEFAULT_MODEL,
                'ai.dossier.model' => self::EVAL_DEFAULT_MODEL,
            ]);
            $applied[] = 'model='.self::EVAL_DEFAULT_MODEL;
            $warnings[] = 'AI_MODEL ontbrak of was inerte default (leeg/gpt-4o-mini); eval gebruikt '
                .self::EVAL_DEFAULT_MODEL
                .' i.p.v. AI_MODEL uit env. Zet AI_MODEL expliciet als je een ander model wilt.';
        }

        config([
            'ai.text_inference.enabled' => true,
            'ai.photo_inference.enabled' => true,
            'ai.dossier.enabled' => true,
        ]);
        $applied[] = 'inference flags on';

        $daily = config('ai.budget.daily_cents');
        $monthly = config('ai.budget.monthly_cents');
        if (($daily === null || $daily === '') && ($monthly === null || $monthly === '')) {
            config(['ai.budget.daily_cents' => self::EVAL_DEFAULT_DAILY_BUDGET_CENTS]);
            $applied[] = 'budget.daily_cents='.self::EVAL_DEFAULT_DAILY_BUDGET_CENTS;
        }

        return ['applied' => $applied, 'warnings' => $warnings];
    }

    public function resolveApiKey(): ?string
    {
        $configKey = config('ai.api_key');
        if (is_string($configKey) && trim($configKey) !== '') {
            return trim($configKey);
        }

        foreach (['AI_API_KEY', 'OPENROUTER_API_KEY'] as $name) {
            $value = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }
}
