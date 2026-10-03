<?php

declare(strict_types=1);

/**
 * Airco template v21 — matenscherm L+B, optionele route-/afvoerfoto, muurfoto’s (BL-124).
 *
 * - Lengte en breedte op één wizardscherm (`meta.wizard_group = room_dimensions`).
 * - Optioneel wanneer m² al bekend is; L×B leidt m² af (bestaande derived_lxw).
 * - `pipe_route_photos` optioneel met “Weet ik niet / sla over”; route blijft open punt.
 * - `indoor_unit_position_photo`: optionele foto’s van de muur binnen én buiten op de
 *   gewenste binnenunitplek.
 * - `drain_photo` altijd optioneel (geen require bij “Weet ik niet”).
 *
 * Gepubliceerde v1–v20 blijven ongewijzigd (ADR-0001).
 *
 * @return array<string, mixed>
 */

/** @var array<string, mixed> $config */
$config = require __DIR__.'/v20.php';

$config['version'] = 21;
$config['change_notes'] = 'BL-124: één matenscherm L+B; optionele route- en afvoerfoto met sla-over; muurfoto’s binnen/buiten op gewenste binnenunitplek.';

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

        if ($key === 'room_length_m') {
            $questions[$questionIndex]['label'] = 'Lengte (m)';
            $questions[$questionIndex]['help_text'] = 'Optioneel als het vloeroppervlak in m² al bekend is. Vul lengte en breedte samen in.';
            $questions[$questionIndex]['is_required'] = false;
            $meta = is_array($question['meta'] ?? null) ? $question['meta'] : [];
            $meta['wizard_group'] = 'room_dimensions';
            $meta['wizard_group_title'] = 'Lengte en breedte van de ruimte';
            $meta['wizard_group_help'] = 'Vul beide in als je ze weet. Bij bekende m² mag je dit overslaan; uit L×B berekenen we het oppervlak.';
            $questions[$questionIndex]['meta'] = $meta;
        }

        if ($key === 'room_width_m') {
            $questions[$questionIndex]['label'] = 'Breedte (m)';
            $questions[$questionIndex]['help_text'] = 'Optioneel als het vloeroppervlak in m² al bekend is.';
            $questions[$questionIndex]['is_required'] = false;
            $meta = is_array($question['meta'] ?? null) ? $question['meta'] : [];
            $meta['wizard_group'] = 'room_dimensions';
            $questions[$questionIndex]['meta'] = $meta;
        }

        if ($key === 'indoor_unit_position_photo') {
            $questions[$questionIndex]['label'] = 'Foto van de muur binnen en buiten op de gewenste plek (optioneel)';
            $questions[$questionIndex]['help_text'] = 'Laat de muur zien waar de binnenunit zou kunnen hangen — bij voorkeur één foto van binnen en één van buiten op dezelfde plek. Geen technische route hoeft; de installateur beoordeelt de leidingroute.';
            $questions[$questionIndex]['photo_instructions'] = 'Maak een foto van de muur aan de binnenkant én, als het kan, vanaf buiten op dezelfde plek. Wijs eventueel met de hand of een briefje de gewenste hoogte aan.';
            $questions[$questionIndex]['is_required'] = false;
            $meta = is_array($question['meta'] ?? null) ? $question['meta'] : [];
            $meta['max_files'] = max(2, (int) ($meta['max_files'] ?? 3));
            $meta['allow_skip'] = true;
            $meta['skip_label'] = 'Weet ik niet / sla over';
            $questions[$questionIndex]['meta'] = $meta;
        }

        if ($key === 'pipe_route_photos') {
            $questions[$questionIndex]['label'] = 'Foto’s van de vermoedelijke leidingroute (optioneel)';
            $questions[$questionIndex]['help_text'] = 'Alleen als je al iets herkent. Weet je het niet, sla dan over — de route blijft een open punt voor de installateur.';
            $questions[$questionIndex]['photo_instructions'] = 'Optioneel: muren of plafonds waar leidingen langs zouden kunnen lopen. Geen foto nodig als je het niet weet.';
            $questions[$questionIndex]['is_required'] = false;
            $meta = is_array($question['meta'] ?? null) ? $question['meta'] : [];
            $meta['allow_skip'] = true;
            $meta['skip_label'] = 'Weet ik niet / sla over';
            $questions[$questionIndex]['meta'] = $meta;
            $questions[$questionIndex]['rules'] = [];
        }

        if ($key === 'drain_photo') {
            $questions[$questionIndex]['label'] = 'Foto van de plek waar condenswater weg kan (optioneel)';
            $questions[$questionIndex]['help_text'] = 'Laat dakgoot, regenpijp, tuin, gevel of een andere afvoerplek zien als je die ziet. Weet je het niet, sla dan over — de installateur houdt dit als open punt.';
            $questions[$questionIndex]['photo_instructions'] = 'Optioneel: één of meer foto’s van de plek waar water weg zou kunnen. Liefst met de omgeving erbij.';
            $questions[$questionIndex]['is_required'] = false;
            $meta = is_array($question['meta'] ?? null) ? $question['meta'] : [];
            $meta['allow_skip'] = true;
            $meta['skip_label'] = 'Weet ik niet / sla over';
            $questions[$questionIndex]['meta'] = $meta;
            // Geen require meer bij lege/“Weet ik niet”-observatie.
            $questions[$questionIndex]['rules'] = [];
        }
    }

    $sections[$sectionIndex]['questions'] = $questions;
}

$config['sections'] = $sections;

return $config;
