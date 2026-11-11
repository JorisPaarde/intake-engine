<?php

declare(strict_types=1);

/**
 * Airco template v30 — eenduidige buitenunit-fototip + optionele meterkast-extra (BL-158).
 *
 * - outdoor_location_photos / around_house_photos: één klantvriendelijke tip
 *   (zelfde tekst op mobiel en desktop; geen “hele gevel” vs “één muur”).
 * - fusebox_photo_extra: niet verplicht; help zonder installateursjargon (“fase”).
 *
 * Bouwt op v29. Gepubliceerde v1–v29 blijven ongewijzigd (ADR-0001).
 *
 * @return array<string, mixed>
 */

/** @var array<string, mixed> $config */
$config = require __DIR__.'/v29.php';

$config['version'] = 30;
$config['change_notes'] = 'Eenduidige buitenunit-fototip; optionele meterkast-extra zonder fase-jargon (BL-158).';

$outdoorTip = 'Maak een duidelijke foto van de gevel of buitenmuur waar de buitenunit kan komen. Neem genoeg omgeving mee zodat de plek herkenbaar is.';

/** @var list<array<string, mixed>> $sections */
$sections = $config['sections'];

foreach ($sections as $sectionIndex => $section) {
    /** @var list<array<string, mixed>> $questions */
    $questions = $section['questions'] ?? [];

    foreach ($questions as $questionIndex => $question) {
        $key = $question['key'] ?? null;

        if ($key === 'outdoor_location_photos') {
            // Eén tip: photo_instructions in het uploadvlak (niet ook help_text erboven).
            $questions[$questionIndex]['photo_instructions'] = $outdoorTip;
            $questions[$questionIndex]['help_text'] = null;
        }

        if ($key === 'around_house_photos') {
            $questions[$questionIndex]['help_text'] = $outdoorTip;
            $questions[$questionIndex]['photo_instructions'] = null;
        }

        if ($key === 'fusebox_photo_extra') {
            $questions[$questionIndex]['is_required'] = false;
            $questions[$questionIndex]['help_text'] = 'Alleen nodig als de eerste foto te onscherp was. Je mag ook doorgaan; de installateur kijkt dan mee.';
            $questions[$questionIndex]['photo_instructions'] = 'Fotografeer opnieuw recht van voren, dichterbij, met scherp leesbare groepen en hoofdschakelaar. Een vrije plek in de meterkast voor een extra groep is handig om mee te nemen.';
        }

        if ($key === 'fusebox_photo') {
            $questions[$questionIndex]['photo_instructions'] = 'Open de meterkast en fotografeer de hele kast recht van voren: groepen, hoofdschakelaar en eventuele vrije plek voor een extra groep, duidelijk leesbaar.';
        }
    }

    foreach ($questions as $questionIndex => $question) {
        $questions[$questionIndex]['sort_order'] = $questionIndex + 1;
    }

    $sections[$sectionIndex]['questions'] = $questions;
}

$config['sections'] = $sections;

return $config;
