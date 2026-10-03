<?php

declare(strict_types=1);

/**
 * Airco template v17 — technische beslissingen uit de klantflow (klanttest 2026-10-02 P0 / BL-116).
 *
 * Klantfilter: alleen TechnicalDecisionKeys (ADR-0015); geen meta.installer_decision.
 *
 * Condens: drain_location is optionele observatie met zichtbare keuzes; drain_photo is
 * altijd zichtbaar — verplicht bij leeg/“Weet ik niet”, anders optioneel.
 * outdoor_mount_type is optionele wens.
 *
 * Gepubliceerde v1–v16 blijven inhoudelijk ongewijzigd (ADR-0001); runtime-filter dekt open links.
 *
 * @return array<string, mixed>
 */

/** @var array<string, mixed> $config */
$config = require __DIR__.'/v16.php';

$config['version'] = 17;
$config['change_notes'] = 'Klanttest P0/BL-116: optionele drain_location-observatie + altijd zichtbare afvoerfoto; optionele outdoor_mount-wens; technische sleutels uit klantflow via TechnicalDecisionKeys.';

/** @var list<array<string, mixed>> $sections */
$sections = $config['sections'];

foreach ($sections as $sectionIndex => $section) {
    /** @var list<array<string, mixed>> $questions */
    $questions = $section['questions'];

    foreach ($questions as $questionIndex => $question) {
        $key = $question['key'] ?? null;
        if (! is_string($key)) {
            continue;
        }

        if ($key === 'drain_location') {
            $questions[$questionIndex]['label'] = 'Waar zie je in de buurt een afvoer? (optioneel)';
            $questions[$questionIndex]['help_text'] = 'Alleen wat je ziet of vermoedt. Weet je het niet, kies dan “Weet ik niet” of sla over — we vragen altijd een foto. De installateur bepaalt pomp, afschot en route.';
            $questions[$questionIndex]['is_required'] = false;
            $questions[$questionIndex]['options'] = [
                [
                    'value' => 'outside_nearby',
                    'label' => 'Regenpijp of putje buiten in de buurt',
                    'sort_order' => 1,
                ],
                [
                    'value' => 'indoor_nearby',
                    'label' => 'Afvoer binnen in de buurt (keuken, badkamer, wasmachine)',
                    'sort_order' => 2,
                ],
                [
                    'value' => 'unknown',
                    'label' => 'Weet ik niet',
                    'sort_order' => 3,
                ],
            ];
        }

        if ($key === 'drain_photo') {
            $questions[$questionIndex]['label'] = 'Foto van de plek waar condenswater weg kan';
            $questions[$questionIndex]['help_text'] = 'Laat dakgoot, regenpijp, tuin, gevel of een andere afvoerplek zien. Je hoeft niet te beoordelen of er een pomp nodig is.';
            $questions[$questionIndex]['photo_instructions'] = 'Maak één of meer foto’s van de plek waar water weg zou kunnen. Liefst met de omgeving erbij.';
            // Altijd zichtbaar; verplicht alleen bij lege of onbekende afvoerobservatie.
            $questions[$questionIndex]['is_required'] = false;
            $questions[$questionIndex]['rules'] = [
                [
                    'source_question_key' => 'drain_location',
                    'operator' => 'not_in',
                    'value' => ['values' => ['outside_nearby', 'indoor_nearby']],
                    'effect' => 'require',
                ],
            ];
        }

        if ($key === 'outdoor_mount_type') {
            $questions[$questionIndex]['label'] = 'Heb je een voorkeur voor de bevestiging van de buitenunit?';
            $questions[$questionIndex]['help_text'] = 'Optioneel. Geef je voorkeur; de installateur bepaalt de definitieve bevestiging.';
            $questions[$questionIndex]['is_required'] = false;

            if (isset($question['options']) && is_array($question['options'])) {
                foreach ($question['options'] as $optionIndex => $option) {
                    if (($option['value'] ?? null) === 'unknown') {
                        $questions[$questionIndex]['options'][$optionIndex]['label'] = 'Geen voorkeur';
                    }
                }
            }
        }
    }

    foreach ($questions as $questionIndex => $question) {
        $questions[$questionIndex]['sort_order'] = $questionIndex + 1;
    }

    $sections[$sectionIndex]['questions'] = $questions;
}

$config['sections'] = $sections;

return $config;
