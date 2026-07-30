<?php

declare(strict_types=1);

/**
 * Airco template v10 — de klant benoemt gewenste ruimtes, geen technische units.
 *
 * De bestaande sleutel `indoor_unit_count` blijft bewust behouden als
 * compatibiliteitsanker voor repeatable sections en historische antwoorden. In de
 * klanttaal betekent de waarde vanaf deze versie het aantal gewenste ruimtes. De
 * uiteindelijke single-/multi-splitconfiguratie volgt pas in het technische dossier.
 *
 * @return array<string, mixed>
 */

/** @var array<string, mixed> $config */
$config = require __DIR__.'/v9.php';

$config['version'] = 10;
$config['change_notes'] = 'De klant benoemt gewenste ruimtes in plaats van binnenunits; binnen-/buitenunitconfiguratie wordt pas als technische installatieoptie gekozen.';

/** @var list<array<string, mixed>> $sections */
$sections = $config['sections'];

foreach ($sections as $sectionIndex => $section) {
    if ($section['key'] === 'rooms') {
        $sections[$sectionIndex]['description'] = 'Per gewenste ruimte één korte, begeleide opname.';
    }

    /** @var list<array<string, mixed>> $questions */
    $questions = $section['questions'];

    foreach ($questions as $questionIndex => $question) {
        $meta = is_array($question['meta'] ?? null) ? $question['meta'] : [];

        if ($question['key'] === 'indoor_unit_count') {
            $questions[$questionIndex]['label'] = 'Hoeveel ruimtes wilt u koelen of verwarmen?';
            $questions[$questionIndex]['help_text'] = 'Noem alleen de gewenste ruimtes. De installateur bepaalt later hoeveel binnen- en buitenunits technisch het beste passen.';
        }

        if ($question['key'] === 'indoor_unit_position_photo') {
            $questions[$questionIndex]['label'] = 'Extra overzicht van wanden en doorgangen';
            $questions[$questionIndex]['help_text'] = 'Laat de andere relevante wanden, ramen en deuren zien. U hoeft zelf geen plek voor een binnenunit te kiezen.';
            $questions[$questionIndex]['photo_instructions'] = 'Maak één extra overzichtsfoto waarop de wandvlakken, ramen, deuren en mogelijke doorgangen samen zichtbaar zijn.';
        }

        if (($meta['installer_prefillable'] ?? false) === true) {
            $skipSources = $meta['skip_when_prefilled_by'] ?? [];
            $skipSources = is_array($skipSources) ? $skipSources : [$skipSources];
            $meta['skip_when_prefilled_by'] = array_values(array_unique([
                ...array_filter($skipSources, 'is_string'),
                'installer',
            ]));
            $questions[$questionIndex]['meta'] = $meta;
        }
    }

    $sections[$sectionIndex]['questions'] = $questions;
}

$config['sections'] = $sections;

return $config;
