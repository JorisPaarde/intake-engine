<?php

declare(strict_types=1);

/**
 * Airco template v11 — eenvoudige klanttaal (ASD-STE100 / NEN-ISO 24495-1).
 *
 * Alleen labels, helpteksten en foto-instructies wijzigen. Keys en regels blijven
 * gelijk aan v10 zodat bestaande enginegedrag intact blijft.
 *
 * @return array<string, mixed>
 */

/** @var array<string, mixed> $config */
$config = require __DIR__.'/v10.php';

$config['version'] = 11;
$config['change_notes'] = 'Klantteksten in gecontroleerd eenvoudig Nederlands: korte zinnen, gewone woorden, geen onnodig jargon.';

/** @var list<array<string, mixed>> $sections */
$sections = $config['sections'];

$labelUpdates = [
    'indoor_unit_count' => [
        'label' => 'Hoeveel ruimtes wilt u koelen of verwarmen?',
        'help_text' => 'Noem alleen de gewenste ruimtes. De installateur bepaalt later hoeveel binnen- en buitenunits het beste passen.',
    ],
    'sun_exposure' => [
        'label' => 'Hoeveel zon krijgt deze ruimte?',
    ],
    'indoor_unit_position_photo' => [
        'label' => 'Extra overzicht van wanden en doorgangen',
        'help_text' => 'Laat de andere relevante wanden, ramen en deuren zien. U hoeft zelf geen plek voor een binnenunit te kiezen.',
        'photo_instructions' => 'Maak één extra overzichtsfoto. Laat wanden, ramen, deuren en mogelijke doorgangen samen zien.',
    ],
    'outdoor_location' => [
        'label' => 'Waar kan de buitenunit staan of hangen?',
    ],
    'outdoor_mount_type' => [
        'label' => 'Hoe wordt de buitenunit bevestigd?',
    ],
    'outdoor_accessibility' => [
        'label' => 'Hoe goed is die plek bereikbaar voor installatie?',
    ],
    'pipe_route_description' => [
        'label' => 'Welke leidingroute lijkt het meest waarschijnlijk?',
    ],
    'pipe_distance_indication' => [
        'label' => 'Hoe lang is de leiding naar de buitenunit ongeveer?',
    ],
    'drillings_needed' => [
        'label' => 'Zijn er waarschijnlijk gaten door muren of vloeren nodig?',
    ],
    'free_group_known' => [
        'label' => 'Is er een vrije groep in de meterkast?',
        'help_text' => 'Een vrije groep is een lege plek in de meterkast voor een aparte stroomgroep.',
    ],
    'natural_fall_possible' => [
        'label' => 'Kan het condenswater waarschijnlijk zonder pomp weglopen?',
        'help_text' => 'Dat kan als de afvoer lager ligt dan de unit, zodat water vanzelf wegstroomt.',
    ],
    'privacy_consent' => [
        'label' => 'Ik geef toestemming voor verwerking van mijn gegevens en foto’s voor deze opname.',
    ],
];

$optionLabelUpdates = [
    'outdoor_mount_type' => [
        'wall' => 'Aan de gevel of muurbeugel',
        'ground' => 'Op de grond',
        'roof' => 'Op het dak',
        'balcony' => 'Op het balkon',
        'unknown' => 'Weet ik nog niet',
    ],
    'pipe_route_description' => [
        'along_facade' => 'Langs de gevel naar buiten',
        'through_attic' => 'Via zolder of kruipruimte',
        'through_room' => 'Door de kamer of gang',
        'short_direct' => 'Korte directe doorboring',
        'unknown' => 'Weet ik nog niet',
    ],
    'sun_exposure' => [
        'low' => 'Weinig zon',
        'medium' => 'Gemiddeld',
        'high' => 'Veel zon',
    ],
];

foreach ($sections as $sectionIndex => $section) {
    if ($section['key'] === 'rooms') {
        $sections[$sectionIndex]['description'] = 'Per gewenste ruimte één korte, begeleide opname.';
    }

    /** @var list<array<string, mixed>> $questions */
    $questions = $section['questions'];

    foreach ($questions as $questionIndex => $question) {
        $key = $question['key'];

        if (isset($labelUpdates[$key])) {
            foreach ($labelUpdates[$key] as $field => $value) {
                $questions[$questionIndex][$field] = $value;
            }
        }

        if (isset($optionLabelUpdates[$key], $question['options']) && is_array($question['options'])) {
            foreach ($question['options'] as $optionIndex => $option) {
                $value = $option['value'] ?? null;
                if (is_string($value) && isset($optionLabelUpdates[$key][$value])) {
                    $questions[$questionIndex]['options'][$optionIndex]['label'] = $optionLabelUpdates[$key][$value];
                }
            }
        }
    }

    $sections[$sectionIndex]['questions'] = $questions;
}

$config['sections'] = $sections;

return $config;
