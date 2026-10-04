<?php

declare(strict_types=1);

/**
 * Airco template v26 — foto-analyseprofielen (B2/#154) + fix bundle E wizard/catalogus.
 *
 * B2: `meta.photo_analysis` op wall_outlet, indoor_unit_position, around_house, drain.
 * Bundle E: preferred_indoor skip “Geen voorkeur — laat installateur kiezen”;
 * room_type/outdoor/room_outlet `unknown` voor catalogus/AI; glazing_type blijft (vanaf v23).
 *
 * Bouwt op v25. Gepubliceerde v1–v25 blijven ongewijzigd (ADR-0001).
 *
 * @return array<string, mixed>
 */

/** @var array<string, mixed> $config */
$config = require __DIR__.'/v25.php';

$config['version'] = 26;
$config['change_notes'] = 'Foto-analyseprofielen (wall_outlet/indoor_position/around_house/drain) + preferred_indoor skip + catalogus unknown.';

/** @var array<string, string> $photoAnalysis */
$photoAnalysis = [
    'wall_outlet_photo' => 'wall_outlet',
    'indoor_unit_position_photo' => 'indoor_position',
    'around_house_photos' => 'around_house',
    'drain_photo' => 'drain',
];

/** @var list<array<string, mixed>> $sections */
$sections = $config['sections'];

$appendUnknown = static function (array $options, int $sortOrder = 99): array {
    foreach ($options as $option) {
        if (($option['value'] ?? null) === 'unknown') {
            return $options;
        }
    }
    $options[] = ['value' => 'unknown', 'label' => 'Weet ik niet', 'sort_order' => $sortOrder];

    return $options;
};

foreach ($sections as $sectionIndex => $section) {
    /** @var list<array<string, mixed>> $questions */
    $questions = $section['questions'] ?? [];

    foreach ($questions as $questionIndex => $question) {
        $key = $question['key'] ?? null;
        if (! is_string($key)) {
            continue;
        }

        if (isset($photoAnalysis[$key])) {
            $meta = is_array($question['meta'] ?? null) ? $question['meta'] : [];
            $meta['photo_analysis'] = $photoAnalysis[$key];
            $questions[$questionIndex]['meta'] = $meta;
            $question = $questions[$questionIndex];
        }

        if ($key === 'preferred_indoor_location') {
            $questions[$questionIndex]['label'] = 'Waar zou de binnenunit mogen hangen?';
            $questions[$questionIndex]['help_text'] = 'Bijvoorbeeld “boven de bank aan de buitenmuur”. Geen idee? Kies dan “Geen voorkeur — laat installateur kiezen”.';
            $questions[$questionIndex]['is_required'] = false;
            $meta = is_array($question['meta'] ?? null) ? $question['meta'] : [];
            $meta['allow_skip'] = true;
            $meta['skip_label'] = 'Geen voorkeur — laat installateur kiezen';
            $meta['skip_value'] = 'Laat installateur kiezen';
            $questions[$questionIndex]['meta'] = $meta;
        }

        if ($key === 'room_type' && isset($question['options']) && is_array($question['options'])) {
            $questions[$questionIndex]['options'] = $appendUnknown($question['options'], 6);
        }

        if (in_array($key, ['outdoor_location', 'outdoor_mount_type', 'outdoor_accessibility'], true)
            && isset($question['options']) && is_array($question['options'])) {
            $questions[$questionIndex]['options'] = $appendUnknown($question['options'], 99);
        }

        if ($key === 'room_outlet_status' && isset($question['options']) && is_array($question['options'])) {
            $questions[$questionIndex]['options'] = $appendUnknown($question['options'], 3);
        }
    }

    foreach ($questions as $questionIndex => $question) {
        $questions[$questionIndex]['sort_order'] = $questionIndex + 1;
    }

    $sections[$sectionIndex]['questions'] = $questions;
}

$config['sections'] = $sections;

return $config;
