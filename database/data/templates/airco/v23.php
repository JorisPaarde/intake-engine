<?php

declare(strict_types=1);

/**
 * Airco template v23 — glazing_type + unknown voor glas/zon (BL-133).
 *
 * - `glass_amount` en `sun_exposure`: optie `unknown` (“Weet ik niet”).
 * - Nieuwe `glazing_type` (enkel/dubbel/HR++/onbekend), optioneel, AI-prefillbaar.
 *
 * Bouwt op v22 (BL-129 klant-UX). Gepubliceerde v1–v22 blijven ongewijzigd (ADR-0001).
 *
 * @return array<string, mixed>
 */

/** @var array<string, mixed> $config */
$config = require __DIR__.'/v22.php';

$config['version'] = 23;
$config['change_notes'] = 'BL-133: glazing_type; unknown-opties voor glass_amount en sun_exposure.';

/** @var list<array<string, mixed>> $sections */
$sections = $config['sections'];

foreach ($sections as $sectionIndex => $section) {
    if (($section['key'] ?? null) !== 'rooms') {
        continue;
    }

    /** @var list<array<string, mixed>> $questions */
    $questions = $section['questions'] ?? [];
    $insertGlazingAt = null;

    foreach ($questions as $questionIndex => $question) {
        $key = $question['key'] ?? null;
        if (! is_string($key)) {
            continue;
        }

        if ($key === 'sun_exposure') {
            /** @var list<array<string, mixed>> $options */
            $options = $question['options'] ?? [];
            $hasUnknown = false;
            foreach ($options as $option) {
                if (($option['value'] ?? null) === 'unknown') {
                    $hasUnknown = true;
                    break;
                }
            }
            if (! $hasUnknown) {
                $options[] = ['value' => 'unknown', 'label' => 'Weet ik niet', 'sort_order' => 4];
                $questions[$questionIndex]['options'] = $options;
            }
            $questions[$questionIndex]['is_required'] = false;
            // Geen photo_analysis hier: dat is voor fotovragen; glas/zon komen uit room-foto-afleiding.
            $meta = is_array($question['meta'] ?? null) ? $question['meta'] : [];
            $meta['skip_when_prefilled_by'] = $meta['skip_when_prefilled_by'] ?? ['ai', 'ai_photo', 'ai_text'];
            $questions[$questionIndex]['meta'] = $meta;
        }

        if ($key === 'glass_amount') {
            /** @var list<array<string, mixed>> $options */
            $options = $question['options'] ?? [];
            $hasUnknown = false;
            foreach ($options as $option) {
                if (($option['value'] ?? null) === 'unknown') {
                    $hasUnknown = true;
                    break;
                }
            }
            if (! $hasUnknown) {
                $options[] = ['value' => 'unknown', 'label' => 'Weet ik niet', 'sort_order' => 4];
                $questions[$questionIndex]['options'] = $options;
            }
            $questions[$questionIndex]['is_required'] = false;
            $meta = is_array($question['meta'] ?? null) ? $question['meta'] : [];
            $meta['skip_when_prefilled_by'] = $meta['skip_when_prefilled_by'] ?? ['ai', 'ai_photo', 'ai_text'];
            $questions[$questionIndex]['meta'] = $meta;
            $insertGlazingAt = $questionIndex + 1;
        }
    }

    if ($insertGlazingAt !== null) {
        $hasGlazing = false;
        foreach ($questions as $question) {
            if (($question['key'] ?? null) === 'glazing_type') {
                $hasGlazing = true;
                break;
            }
        }

        if (! $hasGlazing) {
            $glassSort = (int) ($questions[$insertGlazingAt - 1]['sort_order'] ?? 7);
            array_splice($questions, $insertGlazingAt, 0, [[
                'key' => 'glazing_type',
                'type' => 'single_choice',
                'label' => 'Welk type glas zie je (als je het weet)?',
                'help_text' => 'Optioneel. Enkel glas, dubbel glas of HR++ — of “Weet ik niet”.',
                'is_required' => false,
                'sort_order' => $glassSort + 1,
                'options' => [
                    ['value' => 'single', 'label' => 'Enkel glas', 'sort_order' => 1],
                    ['value' => 'double', 'label' => 'Dubbel glas', 'sort_order' => 2],
                    ['value' => 'hr_plus_plus', 'label' => 'HR++', 'sort_order' => 3],
                    ['value' => 'unknown', 'label' => 'Weet ik niet', 'sort_order' => 4],
                ],
                'meta' => [
                    'skip_when_prefilled_by' => ['ai', 'ai_photo', 'ai_text'],
                ],
            ]]);
        }
    }

    // Herstel room-order inclusief glazing_type naast v22-volgorde.
    $roomOrder = [
        'room_photos',
        'indoor_unit_position_photo',
        'room_name',
        'preferred_indoor_location',
        'room_type',
        'room_size_indication',
        'room_length_m',
        'room_width_m',
        'room_area_m2',
        'ceiling_height_m',
        'sun_exposure',
        'glass_amount',
        'glazing_type',
        'floor_level',
        'room_outlet_status',
        'wall_outlet_photo',
    ];

    $byKey = [];
    foreach ($questions as $question) {
        $byKey[(string) $question['key']] = $question;
    }

    $ordered = [];
    foreach ($roomOrder as $key) {
        if (! isset($byKey[$key])) {
            continue;
        }
        $ordered[] = $byKey[$key];
        unset($byKey[$key]);
    }
    foreach ($byKey as $leftover) {
        $ordered[] = $leftover;
    }
    foreach ($ordered as $questionIndex => $question) {
        $ordered[$questionIndex]['sort_order'] = $questionIndex + 1;
    }

    $sections[$sectionIndex]['questions'] = $ordered;
}

$config['sections'] = $sections;

return $config;
