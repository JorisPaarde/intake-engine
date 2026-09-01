<?php

declare(strict_types=1);

/**
 * Airco template v13 — meterkastfoto vóór vrije-groepvraag (BL-077).
 *
 * Productregel (BL-074 / Jamie-trial): de meterkastfoto komt eerst; AssessFuseboxPhotos
 * leest vrije groep én fase uit diezelfde foto. Een losse ja/nee mag nooit vóór een
 * gevraagde foto staan en vervalt wanneer de foto free_group al met hoge zekerheid
 * oplevert. Alleen als de foto (of extra scherpere foto) free_group niet kan leveren,
 * volgt de bestaande `free_group_known`-vraag.
 *
 * Gepubliceerde v1–v12 blijven ongewijzigd (ADR-0001); nieuwe intakes pinnen op v13.
 *
 * @return array<string, mixed>
 */

/** @var array<string, mixed> $config */
$config = require __DIR__.'/v12.php';

$config['version'] = 13;
$config['change_notes'] = 'BL-077: free_group_known alleen ná gevulde meterkastfoto en alleen als AI free_group niet kon afleiden; geen ja/nee vóór de foto.';

/** @var list<array<string, mixed>> $sections */
$sections = $config['sections'];

foreach ($sections as $sectionIndex => $section) {
    if ($section['key'] !== 'electrical') {
        continue;
    }

    /** @var list<array<string, mixed>> $questions */
    $questions = $section['questions'];

    foreach ($questions as $questionIndex => $question) {
        if ($question['key'] !== 'free_group_known') {
            continue;
        }

        $meta = is_array($question['meta'] ?? null) ? $question['meta'] : [];
        $meta['skip_when_prefilled_by'] = ['ai'];

        $questions[$questionIndex]['is_required'] = true;
        $questions[$questionIndex]['help_text'] = 'Alleen nodig als we het niet duidelijk uit de meterkastfoto konden aflezen. Een vrije groep is een lege plek in de meterkast voor een aparte stroomgroep.';
        $questions[$questionIndex]['meta'] = $meta;
        $questions[$questionIndex]['rules'] = [
            [
                'source_question_key' => 'fusebox_photo',
                'operator' => 'filled',
                'value' => null,
                'effect' => 'show',
            ],
        ];
    }

    foreach ($questions as $questionIndex => $question) {
        $questions[$questionIndex]['sort_order'] = $questionIndex + 1;
    }

    $sections[$sectionIndex]['questions'] = $questions;
}

$config['sections'] = $sections;

return $config;
