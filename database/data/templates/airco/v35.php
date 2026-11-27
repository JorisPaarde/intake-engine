<?php

declare(strict_types=1);

/**
 * Airco template v35 — klantpagina “Dit hebben we gevonden” (BL-174).
 *
 * - Versturen (`truth_confirmation`) wordt de controlepagina: woning, luchtfoto, foto’s en wat we
 *   uit tekst/foto’s/openbare bronnen haalden, in gewone taal (`meta.review_page`).
 * - `privacy_consent` vóór de controlepagina, zodat versturen altijd daar gebeurt.
 * - Nieuw `customer_corrections` (installateursvraag): wat volgens de klant niet klopt. Een correctie
 *   is een klantuitspraak; de tekst-AI leest hem mee na versturen, de installateur ziet hem in het dossier.
 *
 * Bouwt op v34. Gepubliceerde v1–v34 blijven ongewijzigd (ADR-0001).
 *
 * @return array<string, mixed>
 */

/** @var array<string, mixed> $config */
$config = require __DIR__.'/v34.php';

$config['version'] = 35;
$config['change_notes'] = 'BL-174: versturen = controlepagina “Dit hebben we gevonden” met correcties van de klant (customer_corrections).';

/** @var list<array<string, mixed>> $sections */
$sections = $config['sections'];

foreach ($sections as $sectionIndex => $section) {
    if (($section['key'] ?? null) !== 'closing') {
        continue;
    }

    /** @var list<array<string, mixed>> $questions */
    $questions = $section['questions'] ?? [];
    $truthIndex = null;

    foreach ($questions as $questionIndex => $question) {
        if (($question['key'] ?? null) === 'truth_confirmation') {
            $meta = is_array($question['meta'] ?? null) ? $question['meta'] : [];
            $meta['review_page'] = true;
            $questions[$questionIndex]['meta'] = $meta;
            $truthIndex = $questionIndex;
        }
    }

    $corrections = [
        'key' => 'customer_corrections',
        'type' => 'long_text',
        'label' => 'Wat klopt er niet?',
        'help_text' => null,
        'is_required' => false,
        'sort_order' => 0,
        'meta' => [
            // Wordt op de controlepagina getoond, nooit als eigen stap.
            'companion_of' => 'truth_confirmation',
        ],
    ];

    array_splice($questions, $truthIndex ?? count($questions), 0, [$corrections]);

    // Toestemming vóór de controlepagina, zodat “Versturen” altijd op die pagina staat.
    $privacy = null;
    foreach ($questions as $questionIndex => $question) {
        if (($question['key'] ?? null) === 'privacy_consent') {
            $privacy = $question;
            array_splice($questions, $questionIndex, 1);
            break;
        }
    }
    if ($privacy !== null) {
        $correctionsIndex = null;
        foreach ($questions as $questionIndex => $question) {
            if (($question['key'] ?? null) === 'customer_corrections') {
                $correctionsIndex = $questionIndex;
                break;
            }
        }
        array_splice($questions, $correctionsIndex ?? count($questions), 0, [$privacy]);
    }

    foreach ($questions as $questionIndex => $question) {
        $questions[$questionIndex]['sort_order'] = $questionIndex + 1;
    }

    $sections[$sectionIndex]['questions'] = $questions;
}

$config['sections'] = $sections;

return $config;
