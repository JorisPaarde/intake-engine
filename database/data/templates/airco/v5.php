<?php

declare(strict_types=1);

/**
 * Airco intake template v5 — meterkastfoto levert waar mogelijk een door de
 * aanvrager te bevestigen voorzet op (BL-020).
 *
 * @return array<string, mixed>
 */

/** @var array<string, mixed> $config */
$config = require __DIR__.'/v4.php';

$config['version'] = 5;
$config['change_notes'] = 'BL-020: beoordeel meterkastfoto’s als bevestigbare voorzet voor vrije groep en leg fase-inschatting met onzekerheid vast.';

/** @var list<array<string, mixed>> $sections */
$sections = $config['sections'];

foreach ($sections as $sectionIndex => $section) {
    if ($section['key'] !== 'electrical') {
        continue;
    }

    /** @var list<array<string, mixed>> $questions */
    $questions = $section['questions'];

    foreach ($questions as $questionIndex => $question) {
        if ($question['key'] === 'fusebox_photo') {
            $meta = is_array($question['meta'] ?? null) ? $question['meta'] : [];
            $meta['photo_analysis'] = 'fusebox';
            $questions[$questionIndex]['meta'] = $meta;
            $questions[$questionIndex]['photo_instructions'] = 'Open de meterkast en fotografeer groepen, hoofdschakelaar en vrije posities recht van voren en duidelijk leesbaar.';
        }

        if ($question['key'] === 'free_group_known') {
            $questions[$questionIndex]['label'] = 'Is er een vrije groep beschikbaar?';
            $questions[$questionIndex]['help_text'] = 'Wanneer de foto duidelijk genoeg is, staat hier al een inschatting klaar. Controleer de keuze; de installateur beoordeelt de foto definitief.';
        }
    }

    $sections[$sectionIndex]['questions'] = $questions;
}

$config['sections'] = $sections;

return $config;
