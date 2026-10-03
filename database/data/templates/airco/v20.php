<?php

declare(strict_types=1);

/**
 * Airco template v20 — je-vorm + vrije-groepuitleg (klanttest P2 taal).
 *
 * Alleen tekst: geen vragen verwijderen of herordenen (ADR-0001; parallelle
 * stromen mogen extractie/volgorde aanpassen). Bouwt op v19 (BL-119); alleen tekst/je-vorm (BL-120).
 *
 * @return array<string, mixed>
 */

/** @var array<string, mixed> $config */
$config = require __DIR__.'/v19.php';

$config['version'] = 20;
$config['change_notes'] = 'Klanttest P2: consistente je-vorm in sectie- en vraagteksten; vrije-groepuitleg zonder lege plek = vrije groep.';

/**
 * @param  string  $text
 */
$toJe = static function (string $text): string {
    $replacements = [
        'Wat is de reden van uw aanvraag?' => 'Wat is de reden van je aanvraag?',
        'Wilt u koelen, verwarmen of beide?' => 'Wil je koelen, verwarmen of beide?',
        'Hoeveel ruimtes wilt u koelen of verwarmen?' => 'Hoeveel ruimtes wil je koelen of verwarmen?',
        'Heeft u voorkeur voor een merk?' => 'Heb je voorkeur voor een merk?',
        'Wanneer zou u de installatie het liefst laten uitvoeren?' => 'Wanneer zou je de installatie het liefst laten uitvoeren?',
        'Dan volgde dat uit uw eerste toelichting' => 'Dan volgde dat uit je eerste toelichting',
        'U hoeft niets open te maken.' => 'Je hoeft niets open te maken.',
        'U hoeft zelf geen plek voor een binnenunit te kiezen.' => 'Je hoeft zelf geen plek voor een binnenunit te kiezen.',
        'U ziet deze vraag normaal niet.' => 'Je ziet deze vraag normaal niet.',
        'Waarom wilt u een airco en wat zoekt u?' => 'Waarom wil je een airco en wat zoek je?',
    ];

    $text = strtr($text, $replacements);
    $text = preg_replace('/\bWilt u\b/u', 'Wil je', $text) ?? $text;
    $text = preg_replace('/\bWeet u\b/u', 'Weet je', $text) ?? $text;
    $text = preg_replace('/\buw\b/u', 'je', $text) ?? $text;
    $text = preg_replace('/\bUw\b/u', 'Je', $text) ?? $text;

    return $text;
};

/** @var list<array<string, mixed>> $sections */
$sections = $config['sections'];

foreach ($sections as $sectionIndex => $section) {
    foreach (['title', 'description'] as $field) {
        if (is_string($section[$field] ?? null)) {
            $sections[$sectionIndex][$field] = $toJe($section[$field]);
        }
    }

    /** @var list<array<string, mixed>> $questions */
    $questions = $section['questions'] ?? [];

    foreach ($questions as $questionIndex => $question) {
        foreach (['label', 'help_text', 'photo_instructions'] as $field) {
            if (! is_string($question[$field] ?? null)) {
                continue;
            }

            $questions[$questionIndex][$field] = $toJe($question[$field]);
        }

        $key = $question['key'] ?? null;

        if ($key === 'free_group_known') {
            // Feitelijke ja/nee/weet-ik-niet; geen technische oordelen van de klant.
            $questions[$questionIndex]['label'] = 'Is er al een aparte vrije stroomgroep beschikbaar?';
            $questions[$questionIndex]['help_text'] = 'Alleen nodig als we het niet uit de meterkastfoto konden aflezen. Weet je het niet zeker? Kies Weet ik niet — dat is de voor de hand liggende keuze bij twijfel — of maak een scherpere foto van de groepen. Een lege plek in de kast is niet hetzelfde als een beschikbare aparte stroomgroep.';
        }

        if ($key === 'fusebox_photo') {
            $questions[$questionIndex]['help_text'] = 'Deze foto is nodig voor de offerte. Maak hem zo scherp dat groepen en hoofdschakelaar goed leesbaar zijn.';
            // Hele meterkast inclusief lege/vrije posities; klant beoordeelt geen 1-/3-fase.
            $questions[$questionIndex]['photo_instructions'] = 'Open de meterkast en fotografeer de hele kast recht van voren: groepen, hoofdschakelaar en lege of vrije posities, duidelijk leesbaar.';
        }
    }

    $sections[$sectionIndex]['questions'] = $questions;
}

$config['sections'] = $sections;

return $config;
