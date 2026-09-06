<?php

declare(strict_types=1);

/**
 * Airco template v16 — vloeroppervlak via L×B of m²; hoogte apart (BL-101).
 *
 * Voegt optionele `room_area_m2` toe. Lengte/breedte blijven optioneel; klant of AI
 * mag exact m² geven zonder fictieve L×B. Plafondhoogte blijft optioneel en is geen
 * standaard capaciteitsblokker. Gepubliceerde v1–v15 blijven ongewijzigd (ADR-0001).
 *
 * @return array<string, mixed>
 */

/** @var array<string, mixed> $config */
$config = require __DIR__.'/v15.php';

$config['version'] = 16;
$config['change_notes'] = 'BL-101: optioneel vloeroppervlak in m² (`room_area_m2`); L×B of m² als één grondslag; hoogte blijft apart/optioneel.';

/** @var list<array<string, mixed>> $sections */
$sections = $config['sections'];

foreach ($sections as $sectionIndex => $section) {
    if (($section['key'] ?? null) !== 'rooms') {
        continue;
    }

    /** @var list<array<string, mixed>> $questions */
    $questions = $section['questions'];

    foreach ($questions as $questionIndex => $question) {
        if ($question['key'] === 'room_length_m') {
            $questions[$questionIndex]['help_text'] = 'Optioneel als je het vloeroppervlak in m² invult.';
        }
        if ($question['key'] === 'room_width_m') {
            $questions[$questionIndex]['help_text'] = 'Optioneel als je het vloeroppervlak in m² invult.';
        }
        if ($question['key'] === 'ceiling_height_m') {
            $questions[$questionIndex]['label'] = 'Hoogte (m)';
            $questions[$questionIndex]['help_text'] = 'Alleen nodig als de plafondhoogte een besluit verandert (bijv. zolder).';
        }
    }

    $hasArea = false;
    foreach ($questions as $question) {
        if (($question['key'] ?? null) === 'room_area_m2') {
            $hasArea = true;
            break;
        }
    }

    if (! $hasArea) {
        $insertAt = count($questions);
        foreach ($questions as $questionIndex => $question) {
            if (($question['key'] ?? null) === 'room_width_m') {
                $insertAt = $questionIndex + 1;
                break;
            }
        }

        array_splice($questions, $insertAt, 0, [[
            'key' => 'room_area_m2',
            'type' => 'number',
            'label' => 'Vloeroppervlak (m²)',
            'help_text' => 'Optioneel als je lengte en breedte invult. Vul geen verzonnen lengte of breedte in vanuit alleen m².',
            'is_required' => false,
            'sort_order' => 0,
            'validation_rules' => ['min' => 1, 'max' => 500],
            'meta' => ['installer_prefillable' => true],
        ]]);
    }

    $sections[$sectionIndex]['questions'] = $questions;
}

$config['sections'] = $sections;

return $config;
