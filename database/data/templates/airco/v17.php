<?php

declare(strict_types=1);

/**
 * Airco template v17 — technische beslissingen uit de klantflow (klanttest 2026-10-02 P0).
 *
 * De klant toont situatie en wensen (foto’s, feitelijke observaties). Pomp nodig,
 * leidingroute, doorboringen en elektrische voorziening beslist de installateur
 * (AI mag voorstellen). `meta.installer_decision = true` verwijdert die vragen uit
 * de klantwizard en uit klantcompleetheid; ze blijven in de template voor AI/dossier.
 *
 * Condens: bij “Weet ik niet” op afvoerplek volgt een foto-opdracht i.p.v. ja/nee over afschot.
 * Gepubliceerde v1–v16 blijven ongewijzigd (ADR-0001).
 *
 * @return array<string, mixed>
 */

/** @var array<string, mixed> $config */
$config = require __DIR__.'/v16.php';

$config['version'] = 17;
$config['change_notes'] = 'Klanttest P0: technische beslissingen (condenspomp, leidingroute, boringen, vrije groep) uit klantflow; foto/observatie i.p.v. ja/nee; open voor installateur.';

/**
 * Vakinhoudelijke keuzes die de klant niet mag/moet bepalen.
 *
 * @var list<string>
 */
$installerDecisions = [
    'natural_fall_possible',
    'pipe_route_description',
    'pipe_distance_indication',
    'drillings_needed',
    'free_group_known',
];

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

        if (in_array($key, $installerDecisions, true)) {
            $meta['installer_decision'] = true;
            $questions[$questionIndex]['meta'] = $meta;
            // Blijft in template voor AI/installateur; klantwizard/progress negeren deze.
            $questions[$questionIndex]['is_required'] = false;
        }

        if ($key === 'drain_photo') {
            $questions[$questionIndex]['label'] = 'Foto van de plek waar condenswater weg kan';
            $questions[$questionIndex]['help_text'] = 'Laat dakgoot, regenpijp, tuin, gevel of een andere afvoerplek zien. U hoeft niet te beoordelen of er een pomp nodig is.';
            $questions[$questionIndex]['photo_instructions'] = 'Maak één of meer foto’s van de plek waar water weg zou kunnen. Liefst met de omgeving erbij.';
            $questions[$questionIndex]['is_required'] = false;
            $questions[$questionIndex]['rules'] = [
                [
                    'source_question_key' => 'drain_location',
                    'operator' => 'filled',
                    'value' => null,
                    'effect' => 'show',
                ],
                [
                    'source_question_key' => 'drain_location',
                    'operator' => 'equals',
                    'value' => ['value' => 'unknown'],
                    'effect' => 'require',
                ],
            ];
        }

        if ($key === 'drain_location') {
            $questions[$questionIndex]['help_text'] = 'Kies wat u ziet of vermoedt. Weet u het niet, kies dan “Weet ik niet” — daarna vragen we alleen een foto. De installateur bepaalt pomp en afschot.';
        }
    }

    // Condens: observatie + foto vóór (verborgen) technische beslissing.
    if (($section['key'] ?? null) === 'condensate') {
        $order = array_flip(['drain_location', 'drain_photo', 'natural_fall_possible']);
        usort($questions, static function (array $a, array $b) use ($order): int {
            return ($order[$a['key'] ?? ''] ?? PHP_INT_MAX) <=> ($order[$b['key'] ?? ''] ?? PHP_INT_MAX);
        });
    }

    foreach ($questions as $questionIndex => $question) {
        $questions[$questionIndex]['sort_order'] = $questionIndex + 1;
    }

    $sections[$sectionIndex]['questions'] = $questions;
}

$config['sections'] = $sections;

return $config;
