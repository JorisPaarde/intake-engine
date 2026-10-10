<?php

declare(strict_types=1);

use App\Domains\AI\Eval\EvalRuntimeBootstrap;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    unset($_ENV['AI_API_KEY'], $_SERVER['AI_API_KEY'], $_ENV['OPENROUTER_API_KEY'], $_SERVER['OPENROUTER_API_KEY']);
    putenv('AI_API_KEY');
    putenv('OPENROUTER_API_KEY');
});

afterEach(function () {
    unset($_ENV['AI_API_KEY'], $_SERVER['AI_API_KEY'], $_ENV['OPENROUTER_API_KEY'], $_SERVER['OPENROUTER_API_KEY']);
    putenv('AI_API_KEY');
    putenv('OPENROUTER_API_KEY');
});

test('zonder AI_API_KEY activeert fake runtime', function () {
    config([
        'ai.provider' => 'null',
        'ai.api_key' => null,
        'ai.text_inference.enabled' => false,
        'ai.dossier.enabled' => false,
    ]);

    $result = app(EvalRuntimeBootstrap::class)->activate(forceFake: false);

    expect($result['mode'])->toBe('fake')
        ->and($result['api_key_present'])->toBeFalse()
        ->and(config('ai.provider'))->toBe('fake')
        ->and(config('ai.text_inference.enabled'))->toBeTrue()
        ->and(config('ai.dossier.enabled'))->toBeTrue();
});

test('alleen AI_API_KEY in env activeert openai met eval-defaults voor inerte config', function () {
    config([
        'ai.provider' => 'null',
        'ai.api_key' => null,
        'ai.base_url' => 'https://api.openai.com/v1',
        'ai.model' => 'gpt-4o-mini',
        'ai.vision_model' => null,
        'ai.dossier.model' => 'gpt-5.6-terra',
        'ai.text_inference.enabled' => false,
        'ai.photo_inference.enabled' => false,
        'ai.dossier.enabled' => false,
        'ai.budget.daily_cents' => null,
        'ai.budget.monthly_cents' => null,
    ]);
    $_ENV['AI_API_KEY'] = 'sk-or-test-eval-only';
    $_SERVER['AI_API_KEY'] = 'sk-or-test-eval-only';

    $result = app(EvalRuntimeBootstrap::class)->activate(forceFake: false);

    expect($result['mode'])->toBe('openai')
        ->and($result['api_key_present'])->toBeTrue()
        ->and(config('ai.provider'))->toBe('openai')
        ->and(config('ai.api_key'))->toBe('sk-or-test-eval-only')
        ->and(config('ai.base_url'))->toBe(EvalRuntimeBootstrap::EVAL_OPENROUTER_BASE_URL)
        ->and(config('ai.model'))->toBe(EvalRuntimeBootstrap::EVAL_DEFAULT_MODEL)
        ->and(config('ai.vision_model'))->toBe(EvalRuntimeBootstrap::EVAL_DEFAULT_MODEL)
        ->and(config('ai.dossier.model'))->toBe(EvalRuntimeBootstrap::EVAL_DEFAULT_MODEL)
        ->and(config('ai.text_inference.enabled'))->toBeTrue()
        ->and(config('ai.photo_inference.enabled'))->toBeTrue()
        ->and(config('ai.dossier.enabled'))->toBeTrue()
        ->and(config('ai.budget.daily_cents'))->toBe(EvalRuntimeBootstrap::EVAL_DEFAULT_DAILY_BUDGET_CENTS);
});

test('bestaande prod model en base_url blijven staan bij AI_API_KEY', function () {
    config([
        'ai.provider' => 'openai',
        'ai.api_key' => null,
        'ai.base_url' => 'https://openrouter.ai/api/v1',
        'ai.model' => 'google/gemini-2.5-flash-lite',
        'ai.vision_model' => 'google/gemini-2.5-flash-lite',
        'ai.dossier.model' => 'google/gemini-2.5-flash-lite',
        'ai.text_inference.enabled' => false,
        'ai.budget.daily_cents' => 500,
        'ai.budget.monthly_cents' => null,
    ]);
    $_ENV['AI_API_KEY'] = 'sk-or-prod-mirror';
    $_SERVER['AI_API_KEY'] = 'sk-or-prod-mirror';

    $result = app(EvalRuntimeBootstrap::class)->activate(forceFake: false);

    expect($result['mode'])->toBe('openai')
        ->and(config('ai.api_key'))->toBe('sk-or-prod-mirror')
        ->and(config('ai.base_url'))->toBe('https://openrouter.ai/api/v1')
        ->and(config('ai.model'))->toBe('google/gemini-2.5-flash-lite')
        ->and(config('ai.dossier.model'))->toBe('google/gemini-2.5-flash-lite')
        ->and(config('ai.budget.daily_cents'))->toBe(500)
        ->and(config('ai.text_inference.enabled'))->toBeTrue();
});

test('--fake wint van aanwezige AI_API_KEY', function () {
    config([
        'ai.provider' => 'null',
        'ai.api_key' => null,
    ]);
    $_ENV['AI_API_KEY'] = 'sk-or-ignored';
    $_SERVER['AI_API_KEY'] = 'sk-or-ignored';

    $result = app(EvalRuntimeBootstrap::class)->activate(forceFake: true);

    expect($result['mode'])->toBe('fake')
        ->and($result['api_key_present'])->toBeTrue()
        ->and(config('ai.provider'))->toBe('fake');
});
