<?php

declare(strict_types=1);

/**
 * Airco template v26 — ontbrekende foto-analyseprofielen (BL-145 / B2).
 *
 * Zet `meta.photo_analysis` op fotovragen die eerder alleen heuristisch eindigden:
 * wall_outlet, indoor_unit_position, around_house, drain.
 *
 * Bouwt op v25. Gepubliceerde v1–v25 blijven ongewijzigd (ADR-0001).
 *
 * @return array<string, mixed>
 */

/** @var array<string, mixed> $config */
$config = require __DIR__.'/v25.php';

$config['version'] = 26;
$config['change_notes'] = 'Foto-analyseprofielen voor wandcontactdoos, binnenunitplek, rondom huis en afvoer.';

/** @var array<string, string> $photoAnalysis */
$photoAnalysis = [
    'wall_outlet_photo' => 'wall_outlet',
    'indoor_unit_position_photo' => 'indoor_position',
    'around_house_photos' => 'around_house',
    'drain_photo' => 'drain',
];

/** @var list<array<string, mixed>> $sections */
$sections = $config['sections'];

foreach ($sections as $sectionIndex => $section) {
    /** @var list<array<string, mixed>> $questions */
    $questions = $section['questions'] ?? [];

    foreach ($questions as $questionIndex => $question) {
        $key = $question['key'] ?? null;
        if (! is_string($key) || ! isset($photoAnalysis[$key])) {
            continue;
        }

        $meta = is_array($question['meta'] ?? null) ? $question['meta'] : [];
        $meta['photo_analysis'] = $photoAnalysis[$key];
        $questions[$questionIndex]['meta'] = $meta;
    }

    foreach ($questions as $questionIndex => $question) {
        $questions[$questionIndex]['sort_order'] = $questionIndex + 1;
    }

    $sections[$sectionIndex]['questions'] = $questions;
}

$config['sections'] = $sections;

return $config;
