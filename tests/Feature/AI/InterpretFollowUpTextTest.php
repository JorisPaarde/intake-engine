<?php

declare(strict_types=1);

use App\Domains\AI\Actions\InterpretFollowUpText;
use App\Domains\AI\Clients\FakeAiClient;

beforeEach(function () {
    FakeAiClient::reset();
    config([
        'ai.provider' => 'fake',
        'ai.text_inference.enabled' => true,
    ]);
});

afterEach(function () {
    FakeAiClient::reset();
});

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
    $hints = app(InterpretFollowUpText::class)->extractHeightHints(
        'hoogste punt 2,6m, knieschotten 1,2m, schuin dak',
    );

    expect($hints['peak_height_m'])->toBe(2.6)
        ->and($hints['knee_wall_height_m'])->toBe(1.2)
        ->and($hints['mentions_sloped_roof'])->toBeTrue()
        ->and(FakeAiClient::lastRequest()?->promptVersion)->toStartWith('follow-up-text');
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
