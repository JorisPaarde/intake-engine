<?php

declare(strict_types=1);

use App\Domains\AI\Clients\FakeAiClient;
use App\Domains\AI\DTOs\AiCompletionRequest;
use App\Domains\AI\Support\E2eAiScenario;
use App\Support\E2e\E2eScenarioFactory;
use Database\Seeders\IntakeTemplateSeeder;

beforeEach(function () {
    $this->seed(IntakeTemplateSeeder::class);
    config([
        'ai.provider' => 'fake',
        'ai.e2e_helpers_enabled' => true,
        'ai.photo_inference.enabled' => true,
    ]);
    FakeAiClient::reset();
    E2eAiScenario::clear();
});

afterEach(function () {
    FakeAiClient::reset();
    E2eAiScenario::clear();
});

it('hides E2E helper routes when E2E_HELPERS is false at boot', function () {
    // Routes are registered only when e2e_helpers_enabled is true during application boot.
    // Default phpunit env keeps E2E_HELPERS unset/false → 404.
    $this->get('/__e2e__/health')->assertNotFound();
});

it('persists FakeAiClient E2E scenarios for wrong_subject fusebox assessments', function () {
    E2eAiScenario::set(E2eAiScenario::WRONG_SUBJECT);

    $result = app(FakeAiClient::class)->complete(new AiCompletionRequest(
        prompt: 'test',
        input: [],
        promptVersion: 'fusebox-assessment-v1',
    ));

    expect($result->output['subject_match'] ?? null)->toBe('no')
        ->and($result->output['detected_subject'] ?? null)->toBe('outdoor_unit');
});

it('builds an empty living-room customer URL for Playwright', function () {
    $payload = app(E2eScenarioFactory::class)->create('wizard-happy-path');

    expect($payload['access_token'])->toHaveLength(64)
        ->and($payload['customer_url'])->toContain('/o/')
        ->and($payload['scenario'])->toBe('empty-living-room');
});
