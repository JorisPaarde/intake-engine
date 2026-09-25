<?php

declare(strict_types=1);

use App\Domains\AI\Services\AiEnumNormalizer;
use App\Domains\AI\Services\AiValidationFailureFormatter;
use App\Domains\AI\Services\DossierSynthesisOutputNormalizer;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

uses(TestCase::class);

test('AiEnumNormalizer maps synonyms parentheticals and falls back when unknown is allowed', function () {
    $normalizer = new AiEnumNormalizer;

    expect($normalizer->normalize('short (<5m)', ['short', 'medium', 'long', 'unknown'], [], 'unknown'))
        ->toBe('short')
        ->and($normalizer->normalize('  KORT ', ['short', 'medium', 'long', 'unknown'], ['kort' => 'short'], 'unknown'))
        ->toBe('short')
        ->and($normalizer->normalize('middel', ['short', 'medium', 'long', 'unknown'], ['middel' => 'medium'], 'unknown'))
        ->toBe('medium')
        ->and($normalizer->normalize('approx 8 meters', ['short', 'medium', 'long', 'unknown'], [], 'unknown'))
        ->toBe('unknown')
        ->and($normalizer->normalize('approved', ['proposed', 'needs_evidence'], [], null))
        ->toBe('approved');
});

test('DossierSynthesisOutputNormalizer coerces deviant length_class and cost_impact', function () {
    $normalized = app(DossierSynthesisOutputNormalizer::class)->normalize([
        'option_proposals' => [[
            'configuration_type' => 'Single-Split',
            'cost_impact' => 'matig',
            'connections' => [[
                'type' => 'koelleiding',
                'status' => 'plausible',
                'length_class' => 'short (<5m)',
                'cost_impact' => 'ongeveer 200 euro',
            ]],
        ]],
        'exceptions' => [[
            'decision_area_key' => 'stroom',
            'confidence' => 'Hoog',
        ]],
        'customer_tasks' => [[
            'type' => 'foto',
            'decision_area_key' => 'Kosten',
        ]],
    ]);

    expect($normalized['option_proposals'][0]['configuration_type'])->toBe('single_split')
        ->and($normalized['option_proposals'][0]['cost_impact'])->toBe('medium')
        ->and($normalized['option_proposals'][0]['connections'][0]['type'])->toBe('refrigerant')
        ->and($normalized['option_proposals'][0]['connections'][0]['status'])->toBe('proposed')
        ->and($normalized['option_proposals'][0]['connections'][0]['length_class'])->toBe('short')
        ->and($normalized['option_proposals'][0]['connections'][0]['cost_impact'])->toBe('unknown')
        ->and($normalized['exceptions'][0]['decision_area_key'])->toBe('power')
        ->and($normalized['exceptions'][0]['confidence'])->toBe('high')
        ->and($normalized['customer_tasks'][0]['type'])->toBe('photo')
        ->and($normalized['customer_tasks'][0]['decision_area_key'])->toBe('cost_risks');
});

test('AiValidationFailureFormatter lists every attribute and rejected value', function () {
    $validator = Validator::make(
        [
            'option_proposals' => [[
                'connections' => [
                    ['length_class' => 'short (<5m)'],
                    ['length_class' => 'kort'],
                ],
            ]],
        ],
        [
            'option_proposals.*.connections.*.length_class' => ['required', 'in:short,medium,long,unknown'],
        ],
    );

    expect($validator->fails())->toBeTrue();

    try {
        throw new ValidationException($validator);
    } catch (ValidationException $exception) {
        $formatted = app(AiValidationFailureFormatter::class)->fromException($exception);

        expect($formatted)
            ->toContain('option_proposals.0.connections.0.length_class')
            ->toContain('short (<5m)')
            ->toContain('option_proposals.0.connections.1.length_class')
            ->toContain('kort')
            ->not->toContain('(and 1 more error)');
    }
});
