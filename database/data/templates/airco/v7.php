<?php

declare(strict_types=1);

/**
 * Airco intake template v7 — zo min mogelijk vragen die een foto al beantwoordt.
 *
 * v6 zette de adaptieve motor aan en bracht een opname met één binnenunit van 38 naar 29
 * stappen. v7 duwt door tot de bodem van wat afleidbaar is:
 *
 *   1. **Foto vóór álles wat eruit volgt.** In v6 stonden `room_type` en `outdoor_location`
 *      nog vóór hun foto, dus die konden per definitie niet vervallen. Nu opent elke sectie
 *      met de foto.
 *   2. **Derde profiel.** `pipe_route_photos` krijgt `meta.photo_analysis = 'pipe_route'` en
 *      levert route, afstandsindicatie en boringen.
 *   3. **Meterkast trekt gelijk.** `free_group_known` krijgt eindelijk ook
 *      `skip_when_prefilled_by = 'ai'`; bij hoge zekerheid verviel die vraag nog niet.
 *   4. **Weg wat niets toevoegt.** `room_notes` (per ruimte) valt onder `additional_comments`,
 *      en `facade_overview_photo` is overbodig sinds de PDOK-luchtfoto automatisch wordt
 *      vastgelegd — precies wat de v2-notitie al voorzag. `brand_preference` en
 *      `desired_planning` worden één optionele wensenvraag.
 *
 * Wat bewust blíjft staan, ook al zou het "korter" kunnen:
 *   - `ownership`, `pipe_visibility`, `noise_sensitive` — juridische status en voorkeuren
 *     staan niet op een foto.
 *   - `floor_level` — een binnenfoto laat de verdieping niet betrouwbaar zien.
 *   - `truth_confirmation` en `privacy_consent` blijven twee losse vragen. Toestemming moet
 *     specifiek en ongebundeld zijn; die samenvoegen met een juistheidsverklaring maakt haar
 *     niet-vrij. Eén stap winst is dat niet waard.
 *
 * Gepubliceerde v1–v6 blijven ongewijzigd (ADR-0001); alleen nieuwe intakes pinnen op v7.
 *
 * @return array<string, mixed>
 */

/** @var array<string, mixed> $config */
$config = require __DIR__.'/v6.php';

$config['version'] = 7;
$config['change_notes'] = 'Maximale vraagreductie: foto’s openen elke sectie, leidingrouteprofiel toegevoegd, ruimtetype/buitenlocatie/vrije groep vervallen bij hoge zekerheid, en overbodige vragen (ruimtenotities, gevelfoto, losse merk- en planningsvraag) samengevoegd of geschrapt.';

/** @var array<string, string> $photoAnalysis */
$photoAnalysis = [
    'pipe_route_photos' => 'pipe_route',
];

/**
 * Nieuw afleidbaar in v7 — bovenop wat v6 al markeerde.
 *
 * @var list<string>
 */
$derivedFromPhoto = [
    'room_type',
    'outdoor_location',
    'pipe_route_description',
    'pipe_distance_indication',
    'drillings_needed',
    'free_group_known',
];

/**
 * De foto opent nu elke sectie; alles wat eruit volgt komt erna.
 *
 * @var array<string, list<string>>
 */
$reordered = [
    'rooms' => [
        'room_photos',
        'indoor_unit_position_photo',
        'room_type',
        'room_size_indication',
        'sun_exposure',
        'glass_amount',
        'floor_level',
    ],
    'outdoor_unit' => [
        'outdoor_location_photos',
        'outdoor_location',
        'outdoor_mount_type',
        'outdoor_accessibility',
        'noise_sensitive',
    ],
    'pipe_route' => [
        'pipe_route_photos',
        'pipe_route_description',
        'pipe_distance_indication',
        'drillings_needed',
        'pipe_visibility',
    ],
];

/** @var list<string> $dropped */
$dropped = ['room_notes', 'facade_overview_photo', 'brand_preference', 'desired_planning'];

/** @var list<array<string, mixed>> $sections */
$sections = $config['sections'];

foreach ($sections as $sectionIndex => $section) {
    /** @var list<array<string, mixed>> $questions */
    $questions = $section['questions'];

    $questions = array_values(array_filter(
        $questions,
        static fn (array $question): bool => ! in_array($question['key'], $dropped, true),
    ));

    // Eén optionele wensenvraag in plaats van twee losse velden.
    if ($section['key'] === 'request') {
        $questions[] = [
            'key' => 'brand_and_planning_wishes',
            'type' => 'long_text',
            'label' => 'Heeft u wensen over merk, budget of planning?',
            'help_text' => 'Optioneel — alles wat de installateur helpt bij het uitbrengen van een offerte.',
            'is_required' => false,
            'sort_order' => 99,
        ];
    }

    foreach ($questions as $questionIndex => $question) {
        $meta = is_array($question['meta'] ?? null) ? $question['meta'] : [];

        if (isset($photoAnalysis[$question['key']])) {
            $meta['photo_analysis'] = $photoAnalysis[$question['key']];
        }

        if (in_array($question['key'], $derivedFromPhoto, true)) {
            $meta['skip_when_prefilled_by'] = 'ai';
            $questions[$questionIndex]['help_text'] = 'Staat er al een antwoord? Dan volgde dat uit je foto — controleer het even. De installateur beoordeelt de foto zelf ook.';
        }

        if ($meta !== []) {
            $questions[$questionIndex]['meta'] = $meta;
        }
    }

    if (isset($reordered[$section['key']])) {
        $order = array_flip($reordered[$section['key']]);

        usort($questions, static function (array $a, array $b) use ($order): int {
            return ($order[$a['key']] ?? PHP_INT_MAX) <=> ($order[$b['key']] ?? PHP_INT_MAX);
        });
    }

    foreach ($questions as $questionIndex => $question) {
        $questions[$questionIndex]['sort_order'] = $questionIndex + 1;
    }

    $sections[$sectionIndex]['questions'] = $questions;
}

$config['sections'] = $sections;

return $config;
