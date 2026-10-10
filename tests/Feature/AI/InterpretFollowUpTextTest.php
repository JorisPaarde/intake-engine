<?php

declare(strict_types=1);

use App\Domains\AI\Actions\InterpretFollowUpText;
use App\Domains\AI\Clients\FakeAiClient;
use App\Domains\AI\Models\AiRun;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeTemplate;
use App\Enums\AiRunStatus;
use App\Enums\AiRunType;
use App\Enums\IntakeStatus;
use App\Models\User;
use Database\Seeders\IntakeTemplateSeeder;

beforeEach(function () {
    $this->seed(IntakeTemplateSeeder::class);
    FakeAiClient::reset();
    config([
        'ai.provider' => 'fake',
        'ai.text_inference.enabled' => true,
    ]);
});

afterEach(function () {
    FakeAiClient::reset();
});

function followUpTextIntake(): Intake
{
    $version = IntakeTemplate::query()->where('key', 'airco')->firstOrFail()->latestPublishedVersion();

    return Intake::factory()->create([
        'created_by' => User::factory()->create()->id,
        'intake_template_version_id' => $version->id,
        'status' => IntakeStatus::InProgress,
        'customer_email' => 'follow-up-text@example.com',
    ]);
}

test('empty text yields empty height hints without an AI call', function () {
    $hints = app(InterpretFollowUpText::class)->extractHeightHints('   ');

    expect($hints)->toBe(InterpretFollowUpText::EMPTY_HEIGHT_HINTS)
        ->and(FakeAiClient::lastRequest())->toBeNull();
});

test('text-AI off skips the model', function () {
    config(['ai.text_inference.enabled' => false]);

    $hints = app(InterpretFollowUpText::class)->extractHeightHints(
        'hoogste punt 2,6m, knieschotten 1,2m, schuin dak',
    );

    expect($hints)->toBe(InterpretFollowUpText::EMPTY_HEIGHT_HINTS)
        ->and(FakeAiClient::lastRequest())->toBeNull();
});

test('null provider skips the model', function () {
    config(['ai.provider' => 'null']);

    $hints = app(InterpretFollowUpText::class)->extractHeightHints(
        'hoogste punt 2,6m, knieschotten 1,2m, schuin dak',
    );

    expect($hints)->toBe(InterpretFollowUpText::EMPTY_HEIGHT_HINTS)
        ->and(FakeAiClient::lastRequest())->toBeNull();
});

test('fake model extracts nok and knieschot numbers that appear in the source', function () {
    $intake = followUpTextIntake();
    $hints = app(InterpretFollowUpText::class)->extractHeightHints(
        'hoogste punt 2,6m, knieschotten 1,2m, schuin dak',
        $intake,
    );

    expect($hints['peak_height_m'])->toBe(2.6)
        ->and($hints['knee_wall_height_m'])->toBe(1.2)
        ->and($hints['mentions_sloped_roof'])->toBeTrue()
        ->and(FakeAiClient::lastRequest()?->promptVersion)->toStartWith('follow-up-text')
        ->and(FakeAiClient::lastRequest()?->timeoutSeconds)->toBe(InterpretFollowUpText::TIMEOUT_SECONDS);

    $run = AiRun::query()
        ->where('intake_id', $intake->id)
        ->where('type', AiRunType::FollowUpText)
        ->first();

    expect($run)->not->toBeNull()
        ->and($run?->status)->toBe(AiRunStatus::Succeeded)
        ->and($run?->output)->toMatchArray($hints);
});

test('invented numbers that are not in the source are dropped', function () {
    FakeAiClient::alwaysReturn([
        'peak_height_m' => 9.9,
        'knee_wall_height_m' => 1.2,
        'mentions_sloped_roof' => true,
    ]);

    $hints = app(InterpretFollowUpText::class)->extractHeightHints(
        'hoogste punt 2,6m, knieschotten 1,2m, schuin dak',
    );

    expect($hints['peak_height_m'])->toBeNull()
        ->and($hints['knee_wall_height_m'])->toBe(1.2)
        ->and($hints['mentions_sloped_roof'])->toBeTrue();
});

test('flat ceiling without nok or knie is not treated as peak height', function () {
    $hints = app(InterpretFollowUpText::class)->extractHeightHints('Overal 2,40 m, plat plafond.');

    expect($hints['peak_height_m'])->toBeNull()
        ->and($hints['knee_wall_height_m'])->toBeNull()
        ->and($hints['mentions_sloped_roof'])->toBeFalse();
});

test('number quote check requires a standalone digit token', function () {
    FakeAiClient::alwaysReturn([
        'peak_height_m' => 2.6,
        'knee_wall_height_m' => 2,
        'mentions_sloped_roof' => true,
    ]);

    // "2" is only a substring of "12.6" — must not accept knee_wall=2.
    $hints = app(InterpretFollowUpText::class)->extractHeightHints(
        'nok 12,6 meter, knieschot niet genoemd',
    );

    expect($hints['peak_height_m'])->toBeNull()
        ->and($hints['knee_wall_height_m'])->toBeNull();

    FakeAiClient::alwaysReturn([
        'peak_height_m' => 2.6,
        'knee_wall_height_m' => null,
        'mentions_sloped_roof' => true,
    ]);

    $ok = app(InterpretFollowUpText::class)->extractHeightHints('nokhoogte 2.6 meter');

    expect($ok['peak_height_m'])->toBe(2.6);
});

test('integer part of a comma decimal is not a standalone number', function () {
    FakeAiClient::alwaysReturn([
        'peak_height_m' => 2,
        'knee_wall_height_m' => 1,
        'mentions_sloped_roof' => true,
    ]);

    $rejected = app(InterpretFollowUpText::class)->extractHeightHints('nok 2,6 m, knieschot 1,2');

    expect($rejected['peak_height_m'])->toBeNull()
        ->and($rejected['knee_wall_height_m'])->toBeNull();

    FakeAiClient::alwaysReturn([
        'peak_height_m' => 2.6,
        'knee_wall_height_m' => null,
        'mentions_sloped_roof' => true,
    ]);

    $decimal = app(InterpretFollowUpText::class)->extractHeightHints('nok 2,6 m');

    expect($decimal['peak_height_m'])->toBe(2.6);

    FakeAiClient::alwaysReturn([
        'peak_height_m' => 2,
        'knee_wall_height_m' => null,
        'mentions_sloped_roof' => false,
    ]);

    $whole = app(InterpretFollowUpText::class)->extractHeightHints('nok 2 m');

    expect($whole['peak_height_m'])->toBe(2.0);
});

test('sentence-end and list separators do not block a whole number', function () {
    FakeAiClient::alwaysReturn([
        'peak_height_m' => 2,
        'knee_wall_height_m' => null,
        'mentions_sloped_roof' => false,
    ]);

    expect(app(InterpretFollowUpText::class)->extractHeightHints('nok 2.')['peak_height_m'])->toBe(2.0);

    FakeAiClient::alwaysReturn([
        'peak_height_m' => 2,
        'knee_wall_height_m' => 1,
        'mentions_sloped_roof' => false,
    ]);

    $list = app(InterpretFollowUpText::class)->extractHeightHints('nok 2, knie 1');

    expect($list['peak_height_m'])->toBe(2.0)
        ->and($list['knee_wall_height_m'])->toBe(1.0);

    // Still reject integer part of a real decimal.
    FakeAiClient::alwaysReturn([
        'peak_height_m' => 2,
        'knee_wall_height_m' => 1,
        'mentions_sloped_roof' => true,
    ]);

    $decimalParts = app(InterpretFollowUpText::class)->extractHeightHints('nok 2,6 m en knieschot 1,2');

    expect($decimalParts['peak_height_m'])->toBeNull()
        ->and($decimalParts['knee_wall_height_m'])->toBeNull();
});
