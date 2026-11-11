<?php

declare(strict_types=1);

/**
 * Airco template v30 — klanttekst zon/glas vóór foto’s + leidingvoorkeur-uitleg (BL-156).
 *
 * - Gedeelde zon/glas/beglazingsvragen (vóór ruimtefoto’s) verwijzen niet meer naar een
 *   foto die nog niet gemaakt is; labels in meervoud voor het gedeelde scherm.
 * - `pipe_visibility`: klantvriendelijke uitleg + duidelijkere optielabels.
 *
 * Bouwt op v29. Gepubliceerde v1–v29 blijven ongewijzigd (ADR-0001).
 *
 * @return array<string, mixed>
 */

/** @var array<string, mixed> $config */
$config = require __DIR__.'/v29.php';

$config['version'] = 30;
$config['change_notes'] = 'Zon/glas-tekst zonder vroegtijdige fotoverwijzing + meervoud bij gedeeld scherm; uitleg zichtwerk/weggewerkt.';

/** @var list<array<string, mixed>> $sections */
$sections = $config['sections'];

$roomCopy = [
    'sun_exposure' => [
        'label' => 'Hoeveel zon krijgt deze ruimte?',
        'help_text' => 'Schat in hoeveel zon er binnenkomt — bijvoorbeeld bijna geen, af en toe, of het grootste deel van de dag. Weet je het niet, kies dan “Weet ik niet”.',
        'share_label' => 'Hoeveel zon krijgen deze ruimtes?',
        'share_help_text' => 'Schat in hoeveel zon er meestal binnenkomt in deze ruimtes. Verschilt het sterk per ruimte, kies dan hieronder “Verschilt per ruimte”.',
    ],
    'glass_amount' => [
        'label' => 'Hoeveel glas is er in deze ruimte?',
        'help_text' => 'Denk aan ramen en glazen deuren: weinig, gemiddeld, of veel / grote ramen. Weet je het niet, kies dan “Weet ik niet”.',
        'share_label' => 'Hoeveel glas is er in deze ruimtes?',
        'share_help_text' => 'Denk aan ramen en glazen deuren in deze ruimtes. Verschilt het sterk per ruimte, kies dan hieronder “Verschilt per ruimte”.',
    ],
    'glazing_type' => [
        'label' => 'Welk type glas zie je (als je het weet)?',
        'help_text' => 'Optioneel. Enkel glas, dubbel glas of HR++ — of “Weet ik niet”.',
        'share_label' => 'Welk type glas hebben deze ruimtes (als je het weet)?',
        'share_help_text' => 'Optioneel. Verschilt het type glas per ruimte, kies dan hieronder “Verschilt per ruimte”.',
    ],
];

$pipeOptionLabels = [
    'concealed' => 'Weggewerkt (in muur of plafond; vaak duurder)',
    'visible' => 'Zichtwerk (leidingen zichtbaar, in een nette goot)',
    'unknown' => 'Geen voorkeur / weet ik niet',
];

foreach ($sections as $sectionIndex => $section) {
    /** @var list<array<string, mixed>> $questions */
    $questions = $section['questions'] ?? [];

    foreach ($questions as $questionIndex => $question) {
        $key = $question['key'] ?? null;
        if (! is_string($key)) {
            continue;
        }

        if (isset($roomCopy[$key])) {
            $copy = $roomCopy[$key];
            $questions[$questionIndex]['label'] = $copy['label'];
            $questions[$questionIndex]['help_text'] = $copy['help_text'];
            $meta = is_array($question['meta'] ?? null) ? $question['meta'] : [];
            $meta['share_label'] = $copy['share_label'];
            $meta['share_help_text'] = $copy['share_help_text'];
            $questions[$questionIndex]['meta'] = $meta;
        }

        if ($key === 'pipe_visibility') {
            $questions[$questionIndex]['label'] = 'Liever zichtwerk of weggewerkt?';
            $questions[$questionIndex]['help_text'] = 'Zichtwerk: de leidingen blijven zichtbaar op de muur, meestal in een nette goot. Weggewerkt: de leidingen gaan in de muur of het plafond; dat is vaak duurder. Dit is een voorkeur, geen technische keuze.';

            /** @var list<array<string, mixed>> $options */
            $options = $question['options'] ?? [];
            foreach ($options as $optionIndex => $option) {
                $value = $option['value'] ?? null;
                if (is_string($value) && isset($pipeOptionLabels[$value])) {
                    $options[$optionIndex]['label'] = $pipeOptionLabels[$value];
                }
            }
            $questions[$questionIndex]['options'] = $options;
        }

        if ($key === 'privacy_consent') {
            $questions[$questionIndex]['label'] = 'Ik geef toestemming voor verwerking van mijn gegevens en foto’s voor deze opname.';
        }
    }

    $sections[$sectionIndex]['questions'] = $questions;
}

$config['sections'] = $sections;

return $config;
