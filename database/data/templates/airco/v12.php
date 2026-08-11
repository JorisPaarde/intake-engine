<?php

declare(strict_types=1);

/**
 * Airco template v12 — dakkapel als aparte buitenunitplek.
 *
 * Voegt `dormer` toe aan `outdoor_location`, zodat “buitenunit op de dakkapel”
 * niet meer wordt samengevat als “schuin dak”. Overige keys/regels blijven
 * gelijk aan v11.
 *
 * @return array<string, mixed>
 */

/** @var array<string, mixed> $config */
$config = require __DIR__.'/v11.php';

$config['version'] = 12;
$config['change_notes'] = 'Nieuwe keuze “Op of aan de dakkapel” bij de buitenunitplek; overige vragen ongewijzigd.';

/** @var list<array<string, mixed>> $sections */
$sections = $config['sections'];

foreach ($sections as $sectionIndex => $section) {
    /** @var list<array<string, mixed>> $questions */
    $questions = $section['questions'];

    foreach ($questions as $questionIndex => $question) {
        if ($question['key'] !== 'outdoor_location' || ! is_array($question['options'] ?? null)) {
            continue;
        }

        $options = $question['options'];
        $hasDormer = false;

        foreach ($options as $option) {
            if (($option['value'] ?? null) === 'dormer') {
                $hasDormer = true;
                break;
            }
        }

        if ($hasDormer) {
            continue;
        }

        $insertAt = count($options);
        foreach ($options as $optionIndex => $option) {
            if (($option['value'] ?? null) === 'pitched_roof') {
                $insertAt = $optionIndex + 1;
                break;
            }
        }

        array_splice($options, $insertAt, 0, [[
            'value' => 'dormer',
            'label' => 'Op of aan de dakkapel',
            'sort_order' => 7,
        ]]);

        // Houd sort_order uniek en oplopend na de insert.
        foreach ($options as $optionIndex => $option) {
            $options[$optionIndex]['sort_order'] = $optionIndex + 1;
        }

        $questions[$questionIndex]['options'] = $options;
    }

    $sections[$sectionIndex]['questions'] = $questions;
}

$config['sections'] = $sections;

return $config;
