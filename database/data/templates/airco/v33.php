<?php

declare(strict_types=1);

/**
 * Airco template v33 — UX-review 13 nov (punten 8–9).
 *
 * - Verhuurdervraag: kortere label + opties; subtekst korter.
 * - Building-sectie: geen “Gegevens over het gebouw.” onder koop/huur.
 * - “Verschilt dit per ruimte?” als escape-label (link in de wizard).
 *
 * Bouwt op v32. Gepubliceerde v1–v32 blijven ongewijzigd (ADR-0001).
 *
 * @return array<string, mixed>
 */

/** @var array<string, mixed> $config */
$config = require __DIR__.'/v32.php';

$config['version'] = 33;
$config['change_notes'] = 'UX 13 nov: verhuurderstekst; geen building-subtekst; Verschilt dit per ruimte?';

/** @var list<array<string, mixed>> $sections */
$sections = $config['sections'];

foreach ($sections as $sectionIndex => $section) {
    $sectionKey = $section['key'] ?? null;

    if ($sectionKey === 'building') {
        $sections[$sectionIndex]['description'] = null;
    }

    /** @var list<array<string, mixed>> $questions */
    $questions = $section['questions'] ?? [];

    foreach ($questions as $questionIndex => $question) {
        $key = $question['key'] ?? null;
        if (! is_string($key)) {
            continue;
        }

        if ($key === 'landlord_permission') {
            $questions[$questionIndex]['label'] = 'Weet je verhuurder ervan?';
            $questions[$questionIndex]['help_text'] = 'Bij een huurwoning is meestal toestemming nodig.';
            $questions[$questionIndex]['options'] = [
                ['value' => 'yes', 'label' => 'Ja, die vindt het goed', 'sort_order' => 1],
                ['value' => 'not_asked', 'label' => 'Nog niet gevraagd', 'sort_order' => 2],
                ['value' => 'no', 'label' => 'Nee, die is tegen', 'sort_order' => 3],
            ];
        }

        $meta = is_array($question['meta'] ?? null) ? $question['meta'] : [];
        if (($meta['share_across_instances'] ?? false) === true) {
            $meta['share_differs_label'] = 'Verschilt dit per ruimte?';

            foreach (['share_help_text', 'help_text'] as $helpKey) {
                $help = $meta[$helpKey] ?? ($helpKey === 'help_text' ? ($question['help_text'] ?? null) : null);
                if (! is_string($help) || $help === '') {
                    continue;
                }
                $updated = str_replace(
                    ['“Verschilt per ruimte”', '"Verschilt per ruimte"', 'Verschilt per ruimte'],
                    ['“Verschilt dit per ruimte?”', '"Verschilt dit per ruimte?"', 'Verschilt dit per ruimte?'],
                    $help,
                );
                if ($helpKey === 'help_text') {
                    $questions[$questionIndex]['help_text'] = $updated;
                } else {
                    $meta[$helpKey] = $updated;
                }
            }

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
