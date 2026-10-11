<?php

declare(strict_types=1);

use App\Domains\AI\DTOs\RequestPrefillCandidate;
use App\Domains\AI\Eval\EvalFixturePrivacyScan;
use App\Domains\AI\Services\LocalRequestIntentParser;
use App\Domains\AI\Services\RequestPrefillOutcomeClassifier;
use Tests\TestCase;

uses(TestCase::class);

function n24RequestText(): string
{
    return 'Wil airco in de woonkamer (ca. 30 m2, begane grond) en in twee slaapkamers: de grote slaapkamer op de eerste verdieping en een zolderslaapkamer op de tweede verdieping. Meterkast in de gang, 3-fase weet de klant niet. Buitenunit liefst achter in de tuin. Graag voor de zomer.';
}

function n24Catalog(): array
{
    return [
        'sections' => [
            [
                'key' => 'request',
                'title' => 'Aanvraag',
                'is_repeatable' => false,
                'repeat_count_question_key' => null,
                'questions' => [
                    [
                        'key' => 'cooling_heating',
                        'type' => 'single_choice',
                        'label' => 'Koelen/verwarmen',
                        'options' => [
                            ['value' => 'cooling', 'label' => 'Alleen koelen'],
                            ['value' => 'heating', 'label' => 'Alleen verwarmen'],
                            ['value' => 'both', 'label' => 'Beide'],
                        ],
                    ],
                ],
            ],
        ],
    ];
}

test('N24: LocalRequestIntentParser returns null for airco request without cooling words', function () {
    expect(app(LocalRequestIntentParser::class)->parse(n24RequestText()))->toBeNull();
});

test('N24: inferred or unquoted cooling_heating fill is not a confirmed answer', function () {
    config(['intake.fact_confidence_threshold' => 80]);
    $classifier = app(RequestPrefillOutcomeClassifier::class);
    $source = n24RequestText();

    $inferred = $classifier->classifyCatalogOutput([
        'evidence' => 'Aanvraag noemt airco.',
        'fills' => [[
            'question_key' => 'cooling_heating',
            'section_instance_key' => null,
            'confidence' => 'high',
            'value' => ['value' => 'cooling'],
            'evidence' => 'Graag voor de zomer',
            'provenance' => 'inferred',
        ]],
    ], n24Catalog(), [], $source);

    expect($inferred['candidates'][0]->disposition)->not->toBe(RequestPrefillCandidate::DISPOSITION_FILL)
        ->and($inferred['candidates'][0]->provenance?->value)->toBe('inferred');

    $fabricated = $classifier->classifyCatalogOutput([
        'evidence' => 'Aanvraag noemt airco.',
        'fills' => [[
            'question_key' => 'cooling_heating',
            'section_instance_key' => null,
            'confidence' => 'high',
            'value' => ['value' => 'cooling'],
            'evidence' => 'alleen koelen',
            'provenance' => 'stated',
        ]],
    ], n24Catalog(), [], $source);

    expect($fabricated['candidates'][0]->disposition)->not->toBe(RequestPrefillCandidate::DISPOSITION_FILL)
        ->and($fabricated['candidates'][0]->provenance?->value)->toBe('inferred');
});

test('N24: stated quoted Alleen koelen fill remains confirmed', function () {
    config(['intake.fact_confidence_threshold' => 80]);
    $source = 'Wij willen airco in de grote slaapkamer. Alleen koelen.';

    $result = app(RequestPrefillOutcomeClassifier::class)->classifyCatalogOutput([
        'evidence' => 'Expliciete functie.',
        'fills' => [[
            'question_key' => 'cooling_heating',
            'section_instance_key' => null,
            'confidence' => 'high',
            'value' => ['value' => 'cooling'],
            'evidence' => 'Alleen koelen',
            'provenance' => 'stated',
        ]],
    ], n24Catalog(), [], $source);

    expect($result['candidates'][0]->disposition)->toBe(RequestPrefillCandidate::DISPOSITION_FILL)
        ->and($result['candidates'][0]->provenance?->value)->toBe('stated');
});

test('N24: kt12 eval fixture loads, validates and passes privacy scan', function () {
    $path = base_path('tests/Eval/fixtures/request_texts/kt12-airco-zonder-functie-voor-de-zomer.json');
    expect(is_file($path))->toBeTrue();

    $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

    expect($decoded)->toHaveKeys(['id', 'kind', 'origin', 'source_kind', 'text', 'expected', 'components', 'disputed_facts'])
        ->and($decoded['id'])->toBe('kt12-airco-zonder-functie-voor-de-zomer')
        ->and($decoded['kind'])->toBe('request_text')
        ->and($decoded['source_kind'])->toBe('verbatim_notion')
        ->and($decoded['text'])->toBe(n24RequestText())
        ->and($decoded['expected']['cooling_heating'])->toBeNull()
        ->and($decoded['expected']['rooms'])->toHaveCount(3)
        ->and($decoded['disputed_facts'])->toHaveKey('cooling_heating')
        ->and($decoded['disputed_facts']['cooling_heating']['acceptable_alternates'] ?? [])->toContain('cooling');

    $controlPath = base_path('tests/Eval/fixtures/request_texts/kt11-grote-slaapkamer-eerste-zolderslaapkamer-tweede.json');
    $control = json_decode((string) file_get_contents($controlPath), true, 512, JSON_THROW_ON_ERROR);
    expect($control['expected']['cooling_heating'])->toBe('cooling')
        ->and($control['text'])->toContain('Alleen koelen');

    $hits = (new EvalFixturePrivacyScan)->hits((string) json_encode($decoded, JSON_UNESCAPED_UNICODE));
    expect($hits)->toBe([]);
});
