<?php

declare(strict_types=1);

/**
 * Airco template v14 — kortere kruipruimte- en kamermatenlabels (BL-082).
 *
 * Alleen klantlabels/help voor crawl_space_present en L×B×H; structuur en keys
 * blijven gelijk aan v13. Gepubliceerde v1–v13 blijven ongewijzigd (ADR-0001);
 * nieuwe intakes pinnen op v14.
 *
 * @return array<string, mixed>
 */

/** @var array<string, mixed> $config */
$config = require __DIR__.'/v13.php';

$config['version'] = 14;
$config['change_notes'] = 'BL-082: kortere labels/help voor kruipruimte en kamer L×B×H; geen essays.';

/** @var list<array<string, mixed>> $sections */
$sections = $config['sections'];

foreach ($sections as $sectionIndex => $section) {
    if ($section['key'] === 'building') {
        /** @var list<array<string, mixed>> $questions */
        $questions = $section['questions'];

        foreach ($questions as $questionIndex => $question) {
            if ($question['key'] !== 'crawl_space_present') {
                continue;
            }

            $questions[$questionIndex]['label'] = 'Is er een kruipruimte?';
            $questions[$questionIndex]['help_text'] = null;
        }

        $sections[$sectionIndex]['questions'] = $questions;
    }

    if ($section['key'] === 'rooms') {
        /** @var list<array<string, mixed>> $questions */
        $questions = $section['questions'];

        foreach ($questions as $questionIndex => $question) {
            $key = $question['key'] ?? null;

            if ($key === 'room_length_m') {
                $questions[$questionIndex]['label'] = 'Lengte (m)';
                $questions[$questionIndex]['help_text'] = null;
            }

            if ($key === 'room_width_m') {
                $questions[$questionIndex]['label'] = 'Breedte (m)';
                $questions[$questionIndex]['help_text'] = null;
            }

            if ($key === 'ceiling_height_m') {
                $questions[$questionIndex]['label'] = 'Hoogte (m)';
                $questions[$questionIndex]['help_text'] = null;
            }
        }

        $sections[$sectionIndex]['questions'] = $questions;
    }
}

$config['sections'] = $sections;

return $config;
