<?php

declare(strict_types=1);

/**
 * Airco template v27 — afvoerkeuze + optionele foto op één wizardscherm (#14 A1).
 *
 * - `drain_location` + `drain_photo` delen `meta.wizard_group = drain_nearby`.
 * - Titel: "Afvoer in de buurt"; help: foto is optioneel, geen aparte skip-knop.
 * - Foto blijft optioneel; bestaande v1–v26 ongewijzigd (ADR-0001).
 *
 * @return array<string, mixed>
 */

/** @var array<string, mixed> $config */
$config = require __DIR__.'/v26.php';

$config['version'] = 27;
$config['change_notes'] = 'Afvoer: keuze + optionele foto op één scherm (wizard_group drain_nearby).';

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

        if ($key === 'drain_location') {
            $questions[$questionIndex]['label'] = 'Afvoer in de buurt';
            $questions[$questionIndex]['help_text'] = 'Een foto helpt de installateur. Weet je het niet? Ga gewoon verder.';
            $questions[$questionIndex]['is_required'] = false;
            $meta = is_array($question['meta'] ?? null) ? $question['meta'] : [];
            $meta['wizard_group'] = 'drain_nearby';
            $meta['wizard_group_title'] = 'Afvoer in de buurt';
            $meta['wizard_group_help'] = 'Een foto helpt de installateur. Weet je het niet? Ga gewoon verder.';
            $questions[$questionIndex]['meta'] = $meta;
        }

        if ($key === 'drain_photo') {
            $questions[$questionIndex]['label'] = 'Foto van de afvoerplek (optioneel)';
            $questions[$questionIndex]['help_text'] = 'Een foto helpt de installateur. Weet je het niet? Ga gewoon verder.';
            $questions[$questionIndex]['photo_instructions'] = 'Optioneel: dakgoot, regenpijp, tuin, gevel of een andere afvoerplek, liefst met omgeving.';
            $questions[$questionIndex]['is_required'] = false;
            $meta = is_array($question['meta'] ?? null) ? $question['meta'] : [];
            $meta['wizard_group'] = 'drain_nearby';
            // Geen aparte skip-knop: één scherm, alleen Volgende.
            unset($meta['allow_skip'], $meta['skip_label']);
            $questions[$questionIndex]['meta'] = $meta;
            $questions[$questionIndex]['rules'] = [];
        }
    }

    foreach ($questions as $questionIndex => $question) {
        $questions[$questionIndex]['sort_order'] = $questionIndex + 1;
    }

    $sections[$sectionIndex]['questions'] = $questions;
}

$config['sections'] = $sections;

return $config;
