<?php

declare(strict_types=1);

/**
 * Airco template v29 — gedeelde ruimtevagen (BL-153 afronding).
 *
 * Vragen die meestal voor alle ruimtes gelijk zijn (plafondhoogte, verdieping,
 * zon, glas, stopcontactstatus) worden één keer gesteld met escape
 * “Verschilt per ruimte”. Antwoorden blijven per ruimte-instantie in dossier/rapport.
 *
 * Bouwt op v28. Gepubliceerde v1–v28 blijven ongewijzigd (ADR-0001).
 *
 * @return array<string, mixed>
 */

/** @var array<string, mixed> $config */
$config = require __DIR__.'/v28.php';

$config['version'] = 29;
$config['change_notes'] = 'Gedeelde ruimtevagen (hoogte/verdieping/zon/glas/stopcontact) één keer met “Verschilt per ruimte”.';

/** @var list<array<string, mixed>> $sections */
$sections = $config['sections'];

$shareable = [
    'ceiling_height_m',
    'floor_level',
    'sun_exposure',
    'glass_amount',
    'glazing_type',
    'room_outlet_status',
];

foreach ($sections as $sectionIndex => $section) {
    if (($section['key'] ?? null) !== 'rooms') {
        continue;
    }

    /** @var list<array<string, mixed>> $questions */
    $questions = $section['questions'] ?? [];

    foreach ($questions as $questionIndex => $question) {
        $key = $question['key'] ?? null;
        if (! is_string($key) || ! in_array($key, $shareable, true)) {
            continue;
        }

        $meta = is_array($question['meta'] ?? null) ? $question['meta'] : [];
        $meta['share_across_instances'] = true;
        $meta['share_section_title'] = 'Geldt voor alle ruimtes';
        $meta['share_differs_label'] = 'Verschilt per ruimte';
        $questions[$questionIndex]['meta'] = $meta;
    }

    foreach ($questions as $questionIndex => $question) {
        $questions[$questionIndex]['sort_order'] = $questionIndex + 1;
    }

    $sections[$sectionIndex]['questions'] = $questions;
}

$config['sections'] = $sections;

return $config;
