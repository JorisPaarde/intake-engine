<?php

declare(strict_types=1);

/**
 * Airco intake template v9 — de openingsvraag telt mee, en logica die volgt wordt niet
 * meer uitgevraagd.
 *
 * Drie deltas:
 *
 *   1. **De reden van de aanvraag wordt gelezen.** `request_reason` krijgt
 *      `meta.text_analysis`; "slaapkamer en woonkamer worden te warm" beantwoordt daarmee
 *      de functie, het aantal binnenunits én het type van elke ruimte. Tot v8 stelden we
 *      die vragen daarna alsnog.
 *   2. **Cascades die pure logica zijn.** Staat de buitenunit op de grond, dan is de vraag
 *      of er een ladder of steiger nodig is zinloos. Is de route een korte directe
 *      doorboring, dan is de afstand per definitie kort. Dat zijn `show`-regels, geen AI —
 *      de regelmotor kon dit altijd al, de template gebruikte het alleen niet.
 *   3. **Merkvoorkeur wordt een keuzelijst.** Vrije tekst levert onbruikbare data op voor
 *      een offerte; een select geeft de installateur iets om op te filteren. De
 *      planningswens blijft los, want daar is geen zinnige optielijst voor.
 *
 * Gepubliceerde v1–v8 blijven ongewijzigd (ADR-0001); alleen nieuwe intakes pinnen op v9.
 *
 * @return array<string, mixed>
 */

/** @var array<string, mixed> $config */
$config = require __DIR__.'/v8.php';

$config['version'] = 9;
$config['change_notes'] = 'De openingsvraag levert functie, aantal binnenunits en ruimtetypes; conditionele regels laten bereikbaarheid en leidingafstand vervallen waar het antwoord al vastligt; merkvoorkeur is een keuzelijst.';

/**
 * Vragen die uit de openingstekst volgen. Hoge zekerheid laat de vraag vervallen, twijfel
 * levert een bevestigbare voorzet — `room_type` had dit al vanwege de foto-afleiding.
 *
 * @var list<string>
 */
$derivedFromText = ['cooling_heating', 'indoor_unit_count'];

/**
 * Conditionele regels: de vraag verschijnt alleen als het antwoord er niet al uit volgt.
 *
 * Let op de operator: de bronvragen zijn `single_choice`, en `readRuleComparable()` leest
 * voor dat type de sleutel `value` — een lijst onder `values` blijft daar leeg. Vandaar
 * `not_equals` op één waarde in plaats van `not_in`.
 *
 * @var array<string, list<array<string, mixed>>>
 */
$rules = [
    // Staat de unit op de grond, dan is ladder of steiger niet aan de orde.
    'outdoor_accessibility' => [[
        'source_question_key' => 'outdoor_mount_type',
        'operator' => 'not_equals',
        'value' => ['value' => 'ground'],
        'effect' => 'show',
    ]],
    // Een korte directe doorboring is per definitie de korte afstandsklasse.
    'pipe_distance_indication' => [[
        'source_question_key' => 'pipe_route_description',
        'operator' => 'not_equals',
        'value' => ['value' => 'short_direct'],
        'effect' => 'show',
    ]],
];

/** @var list<array<string, mixed>> $sections */
$sections = $config['sections'];

foreach ($sections as $sectionIndex => $section) {
    /** @var list<array<string, mixed>> $questions */
    $questions = $section['questions'];

    foreach ($questions as $questionIndex => $question) {
        $meta = is_array($question['meta'] ?? null) ? $question['meta'] : [];

        if ($question['key'] === 'request_reason') {
            $meta['text_analysis'] = 'request_intent';
            $questions[$questionIndex]['help_text'] = 'Noem gerust welke ruimtes het betreft — dan slaan we die vragen straks over.';
        }

        if (in_array($question['key'], $derivedFromText, true)) {
            $meta['skip_when_prefilled_by'] = ['ai'];
            $questions[$questionIndex]['help_text'] = 'Staat er al een antwoord? Dan volgde dat uit uw eerste toelichting — controleer het even.';
        }

        if (isset($rules[$question['key']])) {
            $questions[$questionIndex]['rules'] = $rules[$question['key']];
        }

        if ($meta !== []) {
            $questions[$questionIndex]['meta'] = $meta;
        }
    }

    // Merkvoorkeur terug als keuzelijst, náást de planningswens.
    if ($section['key'] === 'request') {
        foreach ($questions as $questionIndex => $question) {
            if ($question['key'] !== 'brand_and_planning_wishes') {
                continue;
            }

            $questions[$questionIndex] = [
                'key' => 'brand_preference',
                'type' => 'multi_choice',
                'label' => 'Heeft u voorkeur voor een merk?',
                'help_text' => 'Optioneel — meerdere mogen. Zonder voorkeur kiest de installateur wat het beste past.',
                'is_required' => false,
                'sort_order' => $question['sort_order'],
                'options' => [
                    ['value' => 'no_preference', 'label' => 'Geen voorkeur', 'sort_order' => 1],
                    ['value' => 'daikin', 'label' => 'Daikin', 'sort_order' => 2],
                    ['value' => 'mitsubishi', 'label' => 'Mitsubishi Electric', 'sort_order' => 3],
                    ['value' => 'panasonic', 'label' => 'Panasonic', 'sort_order' => 4],
                    ['value' => 'toshiba', 'label' => 'Toshiba', 'sort_order' => 5],
                    ['value' => 'lg', 'label' => 'LG', 'sort_order' => 6],
                    ['value' => 'samsung', 'label' => 'Samsung', 'sort_order' => 7],
                ],
            ];
        }

        $questions[] = [
            'key' => 'desired_planning',
            'type' => 'single_choice',
            'label' => 'Wanneer zou u de installatie het liefst laten uitvoeren?',
            'is_required' => false,
            'sort_order' => 98,
            'options' => [
                ['value' => 'asap', 'label' => 'Zo snel mogelijk', 'sort_order' => 1],
                ['value' => 'within_3_months', 'label' => 'Binnen 3 maanden', 'sort_order' => 2],
                ['value' => 'before_summer', 'label' => 'Voor de zomer', 'sort_order' => 3],
                ['value' => 'no_rush', 'label' => 'Geen haast', 'sort_order' => 4],
            ],
        ];
    }

    foreach ($questions as $questionIndex => $question) {
        $questions[$questionIndex]['sort_order'] = $questionIndex + 1;
    }

    $sections[$sectionIndex]['questions'] = $questions;
}

$config['sections'] = $sections;

return $config;
