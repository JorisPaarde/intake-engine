<?php

declare(strict_types=1);

/**
 * Airco template v24 — extra ruimtefoto alleen bij expliciete assessment-gap (BL-137).
 *
 * - `indoor_unit_position_photo` alleen zichtbaar wanneer `room_extra_overview_needed=needs_photo`
 *   (AI-assessment), niet standaard na elke bruikbare ruimtefoto.
 * - Interne sleutel `room_extra_overview_needed` (zoals `room_outlet_status`).
 * - `pipe_route_photos`: alleen installateur/follow-up (geen standaard klantstap).
 * - `outdoor_mount_type` blijft in template maar verdwijnt uit klantflow via TechnicalDecisionKeys.
 *
 * Bouwt op v23 (BL-133). Gepubliceerde v1–v23 blijven ongewijzigd (ADR-0001).
 *
 * @return array<string, mixed>
 */

/** @var array<string, mixed> $config */
$config = require __DIR__.'/v23.php';

$config['version'] = 24;
$config['change_notes'] = 'BL-137: extra overzichtsfoto alleen bij room_extra_overview_needed; pipe_route_photos en outdoor_mount_type installateur-only.';

/** @var list<array<string, mixed>> $sections */
$sections = $config['sections'];

foreach ($sections as $sectionIndex => $section) {
    $sectionKey = $section['key'] ?? null;

    /** @var list<array<string, mixed>> $questions */
    $questions = $section['questions'] ?? [];

    if ($sectionKey === 'rooms') {
        $hasExtraOverviewNeeded = false;
        foreach ($questions as $question) {
            if (($question['key'] ?? null) === 'room_extra_overview_needed') {
                $hasExtraOverviewNeeded = true;
                break;
            }
        }

        if (! $hasExtraOverviewNeeded) {
            $questions[] = [
                'key' => 'room_extra_overview_needed',
                'type' => 'single_choice',
                'label' => 'Is een extra ruimte-overzichtsfoto nodig?',
                'help_text' => 'Dit veld vult de app uit de foto. De klant ziet deze vraag niet.',
                'is_required' => false,
                'sort_order' => 0,
                'meta' => [
                    'skip_when_prefilled_by' => ['ai', 'ai_photo'],
                ],
                'options' => [
                    ['value' => 'complete', 'label' => 'Overzicht voldoende', 'sort_order' => 1],
                    ['value' => 'needs_photo', 'label' => 'Extra overzichtsfoto nodig', 'sort_order' => 2],
                ],
            ];
        }

        foreach ($questions as $questionIndex => $question) {
            $key = $question['key'] ?? null;

            if ($key === 'indoor_unit_position_photo') {
                $questions[$questionIndex]['label'] = 'Extra foto: ontbrekende wand of deur';
                $questions[$questionIndex]['help_text'] = 'Alleen nodig als de eerdere ruimtefoto een beslissende wand of deur mist. Je hoeft zelf geen plek voor een binnenunit te kiezen.';
                $questions[$questionIndex]['photo_instructions'] = 'Maak één extra overzichtsfoto van de ontbrekende wand of deur. Geen binnenunitplek zelf kiezen.';
                $questions[$questionIndex]['is_required'] = true;
                $meta = is_array($question['meta'] ?? null) ? $question['meta'] : [];
                // Geen allow_skip meer: alleen tonen wanneer assessment het expliciet vraagt.
                unset($meta['allow_skip'], $meta['skip_label']);
                $questions[$questionIndex]['meta'] = $meta;
                $questions[$questionIndex]['rules'] = [[
                    'source_question_key' => 'room_extra_overview_needed',
                    'operator' => 'equals',
                    'value' => ['value' => 'needs_photo'],
                    'effect' => 'show',
                ]];
            }
        }

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
            'room_extra_overview_needed',
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

        continue;
    }

    if ($sectionKey === 'outdoor_unit') {
        foreach ($questions as $questionIndex => $question) {
            if (($question['key'] ?? null) !== 'outdoor_mount_type') {
                continue;
            }

            $meta = is_array($question['meta'] ?? null) ? $question['meta'] : [];
            $meta['audience'] = 'installer';
            $questions[$questionIndex]['meta'] = $meta;
            $questions[$questionIndex]['help_text'] = 'Bevestigingstype bepaalt de installateur; AI mag een voorstel doen. Geen standaard klantstap.';
        }

        $sections[$sectionIndex]['questions'] = $questions;

        continue;
    }

    if ($sectionKey === 'pipe_route') {
        foreach ($questions as $questionIndex => $question) {
            if (($question['key'] ?? null) !== 'pipe_route_photos') {
                continue;
            }

            $meta = is_array($question['meta'] ?? null) ? $question['meta'] : [];
            $meta['audience'] = 'installer';
            unset($meta['allow_skip'], $meta['skip_label']);
            $questions[$questionIndex]['meta'] = $meta;
            $questions[$questionIndex]['is_required'] = false;
            $questions[$questionIndex]['help_text'] = 'Alleen voor de installateur of een gerichte vervolgtaak. Geen standaard klantstap.';
        }

        $sections[$sectionIndex]['questions'] = $questions;
    }
}

$config['sections'] = $sections;

return $config;
