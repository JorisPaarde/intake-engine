<?php

declare(strict_types=1);

/**
 * Airco template v22 — klant-UX review v1.3.0 (BL-129).
 *
 * - Gewenste binnenunitplek als tekstveld (known-summary + prefill).
 * - Extra ruimte-overzicht noemt ontbrekende wand/deur/stopcontact.
 * - Merk, planning en opmerkingen blijven aparte keys (één scherm via step-builder).
 *
 * Bouwt op v21 (BL-124 formulierrobuustheid). Gepubliceerde v1–v21 blijven
 * ongewijzigd (ADR-0001).
 *
 * @return array<string, mixed>
 */

/** @var array<string, mixed> $config */
$config = require __DIR__.'/v21.php';

$config['version'] = 22;
$config['change_notes'] = 'BL-129 klant-UX v1.3.0: preferred_indoor_location; extra overzicht noemt ontbrekende wand/deur/stopcontact; je-vorm behouden.';

$aiSkipSources = ['ai', 'ai_text', 'ai_photo', 'request_text'];

/** @var list<array<string, mixed>> $sections */
$sections = $config['sections'];

foreach ($sections as $sectionIndex => $section) {
    if (($section['key'] ?? null) !== 'rooms') {
        continue;
    }

    /** @var list<array<string, mixed>> $questions */
    $questions = $section['questions'] ?? [];

    $hasPreferred = false;
    foreach ($questions as $question) {
        if (($question['key'] ?? null) === 'preferred_indoor_location') {
            $hasPreferred = true;
            break;
        }
    }

    if (! $hasPreferred) {
        $insertAt = 0;
        foreach ($questions as $questionIndex => $question) {
            if (($question['key'] ?? null) === 'room_name') {
                $insertAt = $questionIndex + 1;
                break;
            }
        }

        array_splice($questions, $insertAt, 0, [[
            'key' => 'preferred_indoor_location',
            'type' => 'short_text',
            'label' => 'Waar zou de binnenunit mogen hangen?',
            'help_text' => 'Bijvoorbeeld “boven de bank aan de buitenmuur”. Optioneel; helpt de installateur.',
            'is_required' => false,
            'sort_order' => 0,
            'meta' => [
                'installer_prefillable' => true,
                'skip_when_prefilled_by' => $aiSkipSources,
            ],
        ]]);
    }

    foreach ($questions as $questionIndex => $question) {
        $key = $question['key'] ?? null;

        if ($key === 'indoor_unit_position_photo') {
            $questions[$questionIndex]['label'] = 'Extra foto: ontbrekende wand, deur of stopcontact';
            $questions[$questionIndex]['help_text'] = 'Laat de wand, deur of het stopcontact zien die op de eerdere ruimtefoto nog niet duidelijk in beeld was. Je hoeft zelf geen plek voor een binnenunit te kiezen.';
            $questions[$questionIndex]['photo_instructions'] = 'Maak één extra overzichtsfoto van de ontbrekende wand, deur of het stopcontact. Geen binnenunitplek zelf kiezen.';
        }

        if ($key === 'wall_outlet_photo') {
            $questions[$questionIndex]['label'] = 'Extra foto van de wand met stopcontact';
            $questions[$questionIndex]['help_text'] = 'Stopcontacten waren op de ruimtefoto niet duidelijk zichtbaar. Je hoeft zelf geen plek voor een binnenunit te kiezen.';
            $questions[$questionIndex]['photo_instructions'] = 'Fotografeer de wand waar een stopcontact zit, recht van voren. Geen meterkast.';
        }
    }

    // Herstel room-order inclusief preferred_indoor_location.
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
