<?php

declare(strict_types=1);

/**
 * Airco template v18 — foto-first, bekende velden, kamernamen (klanttest 2 okt / BL-118).
 *
 * - Herstelt foto-first sort_order in de ruimtesectie (v16 zette `room_area_m2` op 0).
 * - Voegt optionele `room_name` toe zodat extractie “Slaapkamer ouders” / “Kinderkamer” bewaart.
 * - Bekende tekst-/foto-AI-feiten mogen de losse klantvraag overslaan
 *   (`skip_when_prefilled_by`: `ai` legacy, `ai_text`, `ai_photo`, `request_text`; behoud `installer` waar v16 die had).
 * - Sectievolgorde: aanvraag → ruimtes (foto’s) → buitenunit → woning → rest, zodat
 *   ontbrekende beelden vóór afleidbare vragen komen.
 * - Merkvoorkeur en planning staan in `closing` (na foto’s) wanneer niet geëxtraheerd.
 *
 * Bouwt op v17 (P0 technische beslissingen / BL-116).
 *
 * Gepubliceerde v1–v17 blijven ongewijzigd (ADR-0001).
 *
 * @return array<string, mixed>
 */

/** @var array<string, mixed> $config */
$config = require __DIR__.'/v17.php';

$config['version'] = 18;
$config['change_notes'] = 'Klanttest 2026-10-02 stroom 3/BL-118: foto-first + skip bekende AI-feiten; room_name; sectievolgorde beelden vóór woningvragen; merk/planning na foto’s.';

/** @var list<array<string, mixed>> $sections */
$sections = $config['sections'];

$sectionSort = [
    'request' => 1,
    'rooms' => 2,
    'outdoor_unit' => 3,
    'building' => 4,
    'pipe_route' => 5,
    'electrical' => 6,
    'condensate' => 7,
    'closing' => 8,
];

$skipFromRequestAi = [
    'cooling_heating',
    'indoor_unit_count',
    'brand_preference',
    'desired_planning',
    'ownership',
    'insulation_indication',
    'floor_insulation',
    'crawl_space_present',
    'room_name',
    'room_type',
    'room_length_m',
    'room_width_m',
    'room_area_m2',
    'ceiling_height_m',
    'floor_level',
    'outdoor_location',
    'outdoor_mount_type',
    'noise_sensitive',
    'pipe_visibility',
    'free_group_known',
];

$aiSkipSources = ['ai', 'ai_text', 'ai_photo', 'request_text'];

$roomOrder = [
    'room_photos',
    'indoor_unit_position_photo',
    'room_name',
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

$brandPreference = null;
$desiredPlanning = null;

foreach ($sections as $sectionIndex => $section) {
    $sectionKey = $section['key'] ?? null;

    if (is_string($sectionKey) && isset($sectionSort[$sectionKey])) {
        $sections[$sectionIndex]['sort_order'] = $sectionSort[$sectionKey];
    }

    /** @var list<array<string, mixed>> $questions */
    $questions = $section['questions'];

    if ($sectionKey === 'request') {
        $kept = [];
        foreach ($questions as $question) {
            $key = $question['key'] ?? null;
            if ($key === 'brand_preference') {
                $brandPreference = $question;

                continue;
            }
            if ($key === 'desired_planning') {
                $desiredPlanning = $question;

                continue;
            }
            $kept[] = $question;
        }
        $questions = $kept;
    }

    if ($sectionKey === 'rooms') {
        $hasRoomName = false;
        foreach ($questions as $question) {
            if (($question['key'] ?? null) === 'room_name') {
                $hasRoomName = true;
                break;
            }
        }

        if (! $hasRoomName) {
            $insertAt = 0;
            foreach ($questions as $questionIndex => $question) {
                if (($question['key'] ?? null) === 'room_type') {
                    $insertAt = $questionIndex;
                    break;
                }
            }

            array_splice($questions, $insertAt, 0, [[
                'key' => 'room_name',
                'type' => 'short_text',
                'label' => 'Hoe heet deze ruimte?',
                'help_text' => 'Bijvoorbeeld “Slaapkamer ouders” of “Kinderkamer”. Helpt om foto’s en antwoorden uit elkaar te houden.',
                'is_required' => false,
                'sort_order' => 0,
                'meta' => [
                    'installer_prefillable' => true,
                    'skip_when_prefilled_by' => $aiSkipSources,
                ],
            ]]);
        }

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

        $questions = $ordered;
    }

    if ($sectionKey === 'outdoor_unit') {
        $outdoorOrder = [
            'outdoor_location_photos',
            'around_house_photos',
            'outdoor_location',
            'outdoor_mount_type',
            'outdoor_accessibility',
            'noise_sensitive',
        ];
        $byKey = [];
        foreach ($questions as $question) {
            $byKey[(string) $question['key']] = $question;
        }
        $ordered = [];
        foreach ($outdoorOrder as $key) {
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
        $questions = $ordered;
    }

    if ($sectionKey === 'closing') {
        $closingExtras = [];
        if (is_array($brandPreference)) {
            $brandPreference['is_required'] = false;
            $brandPreference['help_text'] = 'Optioneel. Zonder voorkeur kiest de installateur wat het beste past. Dit houdt de opname niet tegen.';
            $closingExtras[] = $brandPreference;
        }
        if (is_array($desiredPlanning)) {
            $closingExtras[] = $desiredPlanning;
        }
        if ($closingExtras !== []) {
            $questions = [...$closingExtras, ...$questions];
            foreach ($questions as $questionIndex => $question) {
                $questions[$questionIndex]['sort_order'] = $questionIndex + 1;
            }
        }
    }

    foreach ($questions as $questionIndex => $question) {
        $key = $question['key'] ?? null;
        if (! is_string($key)) {
            continue;
        }

        $meta = is_array($question['meta'] ?? null) ? $question['meta'] : [];
        $skipSources = $meta['skip_when_prefilled_by'] ?? null;
        if ($skipSources === null && ! in_array($key, $skipFromRequestAi, true)) {
            continue;
        }

        $skipSources = is_array($skipSources) ? $skipSources : ($skipSources !== null ? [$skipSources] : []);
        // Behoud bestaande `installer` (v10/v12 installer_prefillable); strip niet.

        // Alleen AI-tekst-/fotobronnen + request_text; request_text niet dubbel.
        // Bestaande `ai`-only skip-lijsten (foto-afgeleide vragen) krijgen ai_text/ai_photo erbij.
        if (in_array($key, $skipFromRequestAi, true)
            || in_array('ai', $skipSources, true)
            || in_array('ai_text', $skipSources, true)
            || in_array('ai_photo', $skipSources, true)) {
            $meta['skip_when_prefilled_by'] = array_values(array_unique([
                ...array_values(array_filter($skipSources, 'is_string')),
                ...$aiSkipSources,
            ]));
            $questions[$questionIndex]['meta'] = $meta;
        }
    }

    $sections[$sectionIndex]['questions'] = $questions;
}

$config['sections'] = $sections;

return $config;
