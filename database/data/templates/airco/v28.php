<?php

declare(strict_types=1);

/**
 * Airco template v28 — overbodige klantvragen (Notion #14 punten 2 en 3).
 *
 * - `room_area_m2` deelt `wizard_group = room_dimensions` met L×B (geen apart m²-scherm).
 * - `around_house_photos` is een gerichte vervolgvraag: kop/tekst/skip; runtime
 *   OutdoorPhotoReuse verbergt de stap bij een bruikbare buitenfoto.
 *
 * Bouwt op v27. Gepubliceerde v1–v27 blijven ongewijzigd (ADR-0001).
 *
 * @return array<string, mixed>
 */

/** @var array<string, mixed> $config */
$config = require __DIR__.'/v27.php';

$config['version'] = 28;
$config['change_notes'] = 'Maten: L×B en optioneel m² op één scherm; rondom-huis alleen als vervolgvraag.';

/** @var list<array<string, mixed>> $sections */
$sections = $config['sections'];

foreach ($sections as $sectionIndex => $section) {
    /** @var list<array<string, mixed>> $questions */
    $questions = $section['questions'] ?? [];

    foreach ($questions as $questionIndex => $question) {
        $key = $question['key'] ?? null;
        if (! is_string($key)) {
            continue;
        }

        if ($key === 'room_length_m') {
            $questions[$questionIndex]['is_required'] = false;
            $meta = is_array($question['meta'] ?? null) ? $question['meta'] : [];
            $meta['wizard_group'] = 'room_dimensions';
            $meta['wizard_group_title'] = 'Lengte en breedte van de ruimte';
            $meta['wizard_group_help'] = 'Vul beide in als je ze weet. We berekenen het oppervlak. Weet je alleen de m²? Open dat veld hieronder.';
            $meta['allow_skip'] = true;
            $meta['skip_label'] = 'Weet ik niet / sla over';
            $questions[$questionIndex]['meta'] = $meta;
        }

        if ($key === 'room_width_m') {
            $questions[$questionIndex]['is_required'] = false;
            $meta = is_array($question['meta'] ?? null) ? $question['meta'] : [];
            $meta['wizard_group'] = 'room_dimensions';
            $questions[$questionIndex]['meta'] = $meta;
        }

        if ($key === 'room_area_m2') {
            $questions[$questionIndex]['label'] = 'Oppervlak (m²)';
            $questions[$questionIndex]['help_text'] = 'Alleen als je lengte en breedte niet weet.';
            $questions[$questionIndex]['is_required'] = false;
            $meta = is_array($question['meta'] ?? null) ? $question['meta'] : [];
            $meta['wizard_group'] = 'room_dimensions';
            $questions[$questionIndex]['meta'] = $meta;
        }

        if ($key === 'around_house_photos') {
            $questions[$questionIndex]['label'] = 'We zien de buitenkant nog niet goed';
            $questions[$questionIndex]['help_text'] = 'Maak een foto van de gevel of tuin waar de buitenunit kan komen.';
            $questions[$questionIndex]['photo_instructions'] = null;
            $questions[$questionIndex]['is_required'] = false;
            $meta = is_array($question['meta'] ?? null) ? $question['meta'] : [];
            $meta['max_files'] = max(5, (int) ($meta['max_files'] ?? 5));
            $meta['allow_skip'] = true;
            $meta['skip_label'] = 'Weet ik niet / sla over';
            $meta['reuse_from_photo_keys'] = ['outdoor_location_photos', 'facade_overview_photo'];
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
