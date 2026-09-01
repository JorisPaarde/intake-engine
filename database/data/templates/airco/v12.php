<?php

declare(strict_types=1);

/**
 * Airco template v12 — offerte-kritische bewijsroute na eerste installateurstrial (BL-074).
 *
 * Jamie Elderenbos (28 aug 2026): meterkast, fase, stopcontacten, foto’s rondom het huis,
 * kruipruimte, vloerisolatie en kamermaten — zonder een stapel losse ja/nee-klantvragen.
 *
 *   1. **Meterkast** blijft verplicht; bij onduidelijke foto volgt een extra meterkastfoto
 *      (geen losse 1-/3-fasevraag — fase komt uit AssessFuseboxPhotos).
 *   2. **Stopcontacten** volgen uit de ruimtefoto; alleen een extra wandfoto als die
 *      buiten beeld vallen (geen ja/nee als de foto het al toont).
 *   3. **Foto’s rondom het huis** (gevel/buiten/montageplek) zijn weer verplicht. De
 *      PDOK-luchtfoto en Street View zijn geen vervanging (ToS, voorgevel-only, tuin/achter
 *      meestal ontbreekt).
 *   4. **Kruipruimte** en **vloerisolatie** als kleine waarnemingen; vloerisolatie vervalt
 *      bij EP-Online-prefill (fail-soft zonder key).
 *   5. **Optionele L×B×H** in meters (dossier kan ze al opslaan); geen verplichte
 *      meetspam zoals in v1.
 *
 * Gepubliceerde v1–v11 blijven ongewijzigd (ADR-0001); nieuwe intakes pinnen op v12.
 *
 * @return array<string, mixed>
 */

/** @var array<string, mixed> $config */
$config = require __DIR__.'/v11.php';

$config['version'] = 12;
$config['change_notes'] = 'BL-074: verplichte meterkast- en omgevingsfoto’s; fase/stopcontacten uit foto’s met extra-foto-fallback; kruipruimte; vloerisolatie via EP-Online of korte vraag; optionele kamermeters.';

/** @var list<array<string, mixed>> $sections */
$sections = $config['sections'];

foreach ($sections as $sectionIndex => $section) {
    /** @var list<array<string, mixed>> $questions */
    $questions = $section['questions'];

    if ($section['key'] === 'building') {
        $questions = array_values(array_filter(
            $questions,
            static fn (array $question): bool => ! in_array($question['key'], [
                'crawl_space_present',
                'floor_insulation',
            ], true),
        ));

        $insertAfter = null;
        foreach ($questions as $questionIndex => $question) {
            if ($question['key'] === 'insulation_indication') {
                $insertAfter = $questionIndex;
                break;
            }
        }

        $buildingExtras = [
            [
                'key' => 'floor_insulation',
                'type' => 'single_choice',
                'label' => 'Heeft de woning vloerisolatie?',
                'help_text' => 'Alleen nodig als we dat niet al uit het energielabel weten. U hoeft niets open te maken.',
                'is_required' => false,
                'sort_order' => 0,
                'meta' => [
                    'skip_when_prefilled_by' => ['epo'],
                    'installer_prefillable' => true,
                ],
                'options' => [
                    ['value' => 'yes', 'label' => 'Ja', 'sort_order' => 1],
                    ['value' => 'no', 'label' => 'Nee', 'sort_order' => 2],
                    ['value' => 'unknown', 'label' => 'Weet ik niet', 'sort_order' => 3],
                ],
            ],
            [
                'key' => 'crawl_space_present',
                'type' => 'single_choice',
                'label' => 'Is er een kruipruimte onder de vloer?',
                'help_text' => 'Een kruipruimte is de lage ruimte onder de begane grond. Ga er niet in; zeg alleen of die er is.',
                'is_required' => false,
                'sort_order' => 0,
                'meta' => [
                    'installer_prefillable' => true,
                ],
                'options' => [
                    ['value' => 'yes', 'label' => 'Ja', 'sort_order' => 1],
                    ['value' => 'no', 'label' => 'Nee', 'sort_order' => 2],
                    ['value' => 'unknown', 'label' => 'Weet ik niet', 'sort_order' => 3],
                ],
            ],
        ];

        if ($insertAfter === null) {
            array_push($questions, ...$buildingExtras);
        } else {
            array_splice($questions, $insertAfter + 1, 0, $buildingExtras);
        }
    }

    if ($section['key'] === 'rooms') {
        $questions = array_values(array_filter(
            $questions,
            static fn (array $question): bool => ! in_array($question['key'], [
                'room_length_m',
                'room_width_m',
                'ceiling_height_m',
                'room_outlet_status',
                'wall_outlet_photo',
            ], true),
        ));

        foreach ($questions as $questionIndex => $question) {
            if ($question['key'] === 'room_photos') {
                $questions[$questionIndex]['photo_instructions'] = 'Maak een overzichtsfoto vanuit de deuropening. Laat wanden, ramen en stopcontacten zien als die in beeld passen.';
                $questions[$questionIndex]['help_text'] = 'Eén duidelijke overzichtsfoto helpt. Stopcontacten hoeven geen aparte vraag te zijn als ze al zichtbaar zijn.';
            }
        }

        $sizeIndex = null;
        foreach ($questions as $questionIndex => $question) {
            if ($question['key'] === 'room_size_indication') {
                $sizeIndex = $questionIndex;
                break;
            }
        }

        $meterQuestions = [
            [
                'key' => 'room_length_m',
                'type' => 'number',
                'label' => 'Lengte van de ruimte (m), als u die weet',
                'help_text' => 'Optioneel. Een foto of de groottekeuze hierboven mag ook. Geen exacte meting verplicht.',
                'is_required' => false,
                'sort_order' => 0,
                'validation_rules' => ['min' => 0.5, 'max' => 100],
                'meta' => ['installer_prefillable' => true],
            ],
            [
                'key' => 'room_width_m',
                'type' => 'number',
                'label' => 'Breedte van de ruimte (m), als u die weet',
                'help_text' => 'Optioneel.',
                'is_required' => false,
                'sort_order' => 0,
                'validation_rules' => ['min' => 0.5, 'max' => 100],
                'meta' => ['installer_prefillable' => true],
            ],
            [
                'key' => 'ceiling_height_m',
                'type' => 'number',
                'label' => 'Hoogte van de ruimte (m), als u die weet',
                'help_text' => 'Optioneel. Vaak ongeveer 2,4 tot 2,7 meter.',
                'is_required' => false,
                'sort_order' => 0,
                'validation_rules' => ['min' => 1.5, 'max' => 10],
                'meta' => ['installer_prefillable' => true],
            ],
        ];

        if ($sizeIndex === null) {
            array_push($questions, ...$meterQuestions);
        } else {
            array_splice($questions, $sizeIndex + 1, 0, $meterQuestions);
        }

        $questions[] = [
            'key' => 'room_outlet_status',
            'type' => 'single_choice',
            'label' => 'Zijn stopcontacten op de ruimtefoto zichtbaar?',
            'help_text' => 'Dit veld vult de app uit de foto. U ziet deze vraag normaal niet.',
            'is_required' => false,
            'sort_order' => 0,
            'meta' => [
                'skip_when_prefilled_by' => ['ai'],
            ],
            'options' => [
                ['value' => 'present', 'label' => 'Zichtbaar op de foto', 'sort_order' => 1],
                ['value' => 'needs_photo', 'label' => 'Extra wandfoto nodig', 'sort_order' => 2],
            ],
        ];

        $questions[] = [
            'key' => 'wall_outlet_photo',
            'type' => 'photo',
            'label' => 'Extra foto van de wand met stopcontact',
            'help_text' => 'Alleen nodig als stopcontacten op de overzichtsfoto niet duidelijk in beeld waren.',
            'photo_instructions' => 'Fotografeer de wand waar een stopcontact zit, recht van voren. Geen meterkast.',
            'is_required' => true,
            'sort_order' => 0,
            'meta' => ['max_files' => 3],
            'rules' => [[
                'source_question_key' => 'room_outlet_status',
                'operator' => 'equals',
                'value' => ['value' => 'needs_photo'],
                'effect' => 'show',
            ]],
        ];
    }

    if ($section['key'] === 'outdoor_unit') {
        $questions = array_values(array_filter(
            $questions,
            static fn (array $question): bool => $question['key'] !== 'around_house_photos'
                && $question['key'] !== 'facade_overview_photo',
        ));

        $insertAt = 0;
        foreach ($questions as $questionIndex => $question) {
            if ($question['key'] === 'outdoor_location_photos') {
                $insertAt = $questionIndex + 1;
                break;
            }
        }

        array_splice($questions, $insertAt, 0, [[
            'key' => 'around_house_photos',
            'type' => 'photo',
            'label' => 'Foto’s rondom het huis (gevel, tuin of montageplek)',
            'help_text' => 'Laat de gevels en de plek rondom de woning zien. Een luchtfoto of Street View is geen vervanging.',
            'photo_instructions' => 'Maak foto’s van de relevante gevels, tuin of montageplek. Neem voor- én achterzijde mee als die bij de installatie horen.',
            'is_required' => true,
            'sort_order' => 0,
            'meta' => ['max_files' => 5],
        ]]);
    }

    if ($section['key'] === 'electrical') {
        $questions = array_values(array_filter(
            $questions,
            static fn (array $question): bool => ! in_array($question['key'], [
                'fusebox_clarity',
                'fusebox_photo_extra',
                'electrical_phase',
            ], true),
        ));

        foreach ($questions as $questionIndex => $question) {
            if ($question['key'] === 'fusebox_photo') {
                $questions[$questionIndex]['is_required'] = true;
                $questions[$questionIndex]['label'] = 'Foto van de meterkast';
                $questions[$questionIndex]['help_text'] = 'Deze foto is nodig voor de offerte. De app leest zo mogelijk vrije groep en 1- of 3-fase uit dezelfde foto.';
                $questions[$questionIndex]['photo_instructions'] = 'Open de meterkast en fotografeer groepen, hoofdschakelaar en vrije posities recht van voren en duidelijk leesbaar. Laat ook zien of het 1-fase of 3-fase is.';
                $meta = is_array($question['meta'] ?? null) ? $question['meta'] : [];
                $meta['photo_analysis'] = 'fusebox';
                $questions[$questionIndex]['meta'] = $meta;
            }
        }

        $fuseboxIndex = 0;
        foreach ($questions as $questionIndex => $question) {
            if ($question['key'] === 'fusebox_photo') {
                $fuseboxIndex = $questionIndex;
                break;
            }
        }

        array_splice($questions, $fuseboxIndex + 1, 0, [
            [
                'key' => 'fusebox_clarity',
                'type' => 'single_choice',
                'label' => 'Is de meterkastfoto duidelijk genoeg?',
                'help_text' => 'Dit veld vult de app uit de foto. U ziet deze vraag normaal niet.',
                'is_required' => false,
                'sort_order' => 0,
                'meta' => [
                    'skip_when_prefilled_by' => ['ai'],
                ],
                'options' => [
                    ['value' => 'clear', 'label' => 'Duidelijk genoeg', 'sort_order' => 1],
                    ['value' => 'needs_clearer_photo', 'label' => 'Extra foto nodig', 'sort_order' => 2],
                ],
            ],
            [
                'key' => 'fusebox_photo_extra',
                'type' => 'photo',
                'label' => 'Extra, scherpere foto van de meterkast',
                'help_text' => 'Alleen nodig als de eerste foto te onscherp was of de fase niet duidelijk liet zien. Geen aparte 1-/3-fasevraag.',
                'photo_instructions' => 'Fotografeer opnieuw recht van voren, dichterbij, met scherp leesbare groepen en hoofdschakelaar.',
                'is_required' => true,
                'sort_order' => 0,
                'meta' => [
                    'max_files' => 3,
                    'photo_analysis' => 'fusebox',
                ],
                'rules' => [[
                    'source_question_key' => 'fusebox_clarity',
                    'operator' => 'equals',
                    'value' => ['value' => 'needs_clearer_photo'],
                    'effect' => 'show',
                ]],
            ],
        ]);
    }

    foreach ($questions as $questionIndex => $question) {
        $meta = is_array($question['meta'] ?? null) ? $question['meta'] : [];

        if (($meta['installer_prefillable'] ?? false) === true) {
            $skipSources = $meta['skip_when_prefilled_by'] ?? [];
            $skipSources = is_array($skipSources) ? $skipSources : [$skipSources];
            $meta['skip_when_prefilled_by'] = array_values(array_unique([
                ...array_filter($skipSources, 'is_string'),
                'installer',
            ]));
            $questions[$questionIndex]['meta'] = $meta;
        }
    }

    foreach ($questions as $questionIndex => $question) {
        $questions[$questionIndex]['sort_order'] = $questionIndex + 1;
    }

    $sections[$sectionIndex]['questions'] = $questions;
}

$config['sections'] = $sections;

return $config;
