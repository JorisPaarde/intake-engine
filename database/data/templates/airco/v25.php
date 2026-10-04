<?php

declare(strict_types=1);

/**
 * Airco template v25 — hertest 4 okt P3: drain-tekst + hergebruik rondom-huis-foto’s.
 *
 * - `drain_location` help_text sluit aan op optionele `drain_photo` (geen “altijd een foto”).
 * - `around_house_photos`: overslaan toegestaan wanneer er al bruikbare gevel-/tuinfoto’s zijn;
 *   anders verplicht. Runtime: OutdoorPhotoReuse.
 *
 * Bouwt op v24 (BL-137). Gepubliceerde v1–v24 blijven ongewijzigd (ADR-0001).
 *
 * @return array<string, mixed>
 */

/** @var array<string, mixed> $config */
$config = require __DIR__.'/v24.php';

$config['version'] = 25;
$config['change_notes'] = 'Hertest 4 okt P3: drain-tekst optioneel; around_house hergebruikt gevel-/tuinfoto’s.';

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
            $questions[$questionIndex]['help_text'] = 'Alleen wat je ziet of vermoedt. Weet je het niet, kies dan “Weet ik niet” of sla over. Heb je een foto van de plek? Dat helpt de installateur; weet je het niet, sla dan over. De installateur bepaalt pomp, afschot en route.';
        }

        if ($key === 'drain_photo') {
            $questions[$questionIndex]['label'] = 'Foto van de afvoerplek (optioneel)';
            $questions[$questionIndex]['help_text'] = 'Heb je een foto van de plek? Dat helpt de installateur; weet je het niet, sla dan over.';
            $questions[$questionIndex]['photo_instructions'] = 'Optioneel: dakgoot, regenpijp, tuin, gevel of een andere afvoerplek, liefst met omgeving.';
            $questions[$questionIndex]['is_required'] = false;
            $meta = is_array($question['meta'] ?? null) ? $question['meta'] : [];
            $meta['allow_skip'] = true;
            $meta['skip_label'] = 'Weet ik niet / sla over';
            $questions[$questionIndex]['meta'] = $meta;
            $questions[$questionIndex]['rules'] = [];
        }

        if ($key === 'around_house_photos') {
            $questions[$questionIndex]['label'] = 'Foto’s rondom het huis (gevel, tuin of montageplek)';
            $questions[$questionIndex]['help_text'] = 'Laat de gevels en de plek rondom de woning zien. Heb je al een gevel- of tuinfoto geüpload, dan mag je deze stap overslaan of nog meer toevoegen. Een luchtfoto of Street View is geen vervanging.';
            $questions[$questionIndex]['photo_instructions'] = 'Maak foto’s van de relevante gevels, tuin of montageplek. Neem voor- én achterzijde mee als die bij de installatie horen.';
            // Runtime OutdoorPhotoReuse maakt dit optioneel zodra outdoor/facade bruikbaar is.
            $questions[$questionIndex]['is_required'] = true;
            $meta = is_array($question['meta'] ?? null) ? $question['meta'] : [];
            $meta['max_files'] = max(5, (int) ($meta['max_files'] ?? 5));
            $meta['allow_skip'] = true;
            $meta['skip_label'] = 'Ik heb al genoeg foto’s van gevel of tuin';
            $meta['reuse_from_photo_keys'] = ['outdoor_location_photos', 'facade_overview_photo'];
            $questions[$questionIndex]['meta'] = $meta;
        }
    }

    foreach ($questions as $questionIndex => $question) {
        $questions[$questionIndex]['sort_order'] = $questionIndex + 1;
    }

    $sections[$sectionIndex]['questions'] = $questions;
}

$config['sections'] = $sections;

return $config;
