<?php

declare(strict_types=1);
use App\Domains\Intake\Support\TechnicalDecisionKeys;

/**
 * Airco template v17 — technische beslissingen uit de klantflow (klanttest 2026-10-02 P0 / BL-116).
 *
 * `meta.installer_decision` markeert technische sleutels (zie TechnicalDecisionKeys).
 * De klantwizard filtert op die sleutellijst + meta (ADR-0015); geen apart is_required-mechanisme.
 *
 * Condens: drain_location is optionele observatie; drain_photo verplicht als afvoer
 * leeg/onbekend is, verborgen bij concreet antwoord. outdoor_mount_type is optionele wens.
 *
 * Gepubliceerde v1–v16 blijven inhoudelijk ongewijzigd (ADR-0001); runtime-filter dekt open links.
 *
 * @return array<string, mixed>
 */

/** @var array<string, mixed> $config */
$config = require __DIR__.'/v16.php';

$config['version'] = 17;
$config['change_notes'] = 'Klanttest P0/BL-116: installer_decision-meta; optionele drain_location + afvoerfoto; optionele outdoor_mount-wens; technische sleutels uit klantflow.';

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

        $meta = is_array($question['meta'] ?? null) ? $question['meta'] : [];

        if (TechnicalDecisionKeys::contains($key)) {
            $meta['installer_decision'] = true;
            $questions[$questionIndex]['meta'] = $meta;
        }

        if ($key === 'drain_location') {
            $questions[$questionIndex]['label'] = 'Waar zie je in de buurt een afvoer? (optioneel)';
            $questions[$questionIndex]['help_text'] = 'Alleen wat je ziet of vermoedt. Weet je het niet, kies dan “Weet ik niet” of sla over — daarna vragen we een foto. De installateur bepaalt pomp, afschot en route.';
            $questions[$questionIndex]['is_required'] = false;
        }

        if ($key === 'drain_photo') {
            $questions[$questionIndex]['label'] = 'Foto van de plek waar condenswater weg kan';
            $questions[$questionIndex]['help_text'] = 'Laat dakgoot, regenpijp, tuin, gevel of een andere afvoerplek zien. Je hoeft niet te beoordelen of er een pomp nodig is.';
            $questions[$questionIndex]['photo_instructions'] = 'Maak één of meer foto’s van de plek waar water weg zou kunnen. Liefst met de omgeving erbij.';
            // Verplicht zolang zichtbaar: zichtbaar als afvoer leeg of “Weet ik niet”.
            $questions[$questionIndex]['is_required'] = true;
            $questions[$questionIndex]['rules'] = [
                [
                    'source_question_key' => 'drain_location',
                    'operator' => 'not_in',
                    'value' => ['values' => ['gutter', 'garden', 'sewer', 'outside_wall']],
                    'effect' => 'show',
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
