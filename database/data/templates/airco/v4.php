<?php

declare(strict_types=1);

/**
 * Airco intake template v4 — betrouwbare externe gegevens hoeven niet opnieuw
 * te worden uitgevraagd (BL-019). Gepubliceerde versies blijven immutabel.
 *
 * @return array<string, mixed>
 */

/** @var array<string, mixed> $config */
$config = require __DIR__.'/v3.php';

$config['version'] = 4;
$config['change_notes'] = 'BL-019: sla de bouwjaarvraag over wanneer PDOK/BAG het bouwjaar eenduidig heeft geleverd; handmatige vraag blijft fallback.';

/** @var list<array<string, mixed>> $sections */
$sections = $config['sections'];

foreach ($sections as $sectionIndex => $section) {
    if ($section['key'] !== 'building') {
        continue;
    }

    /** @var list<array<string, mixed>> $questions */
    $questions = $section['questions'];

    foreach ($questions as $questionIndex => $question) {
        if ($question['key'] !== 'build_year') {
            continue;
        }

        $meta = is_array($question['meta'] ?? null) ? $question['meta'] : [];
        $meta['skip_when_prefilled_by'] = 'pdok';
        $questions[$questionIndex]['meta'] = $meta;
        $questions[$questionIndex]['help_text'] = 'Alleen nodig wanneer het bouwjaar niet betrouwbaar uit de BAG kan worden bepaald.';
    }

    $sections[$sectionIndex]['questions'] = $questions;
}

$config['sections'] = $sections;

return $config;
