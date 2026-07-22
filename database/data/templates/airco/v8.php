<?php

declare(strict_types=1);

/**
 * Airco intake template v8 — het geregistreerde energielabel neemt twee vragen over.
 *
 * EP-Online (RVO) legt per adres vast wat we tot nu toe vroegen:
 *
 *   - `insulation_indication` volgt uit de energiebehoefte. In v7 bleef deze vraag bewust
 *     staan omdat bouwjaar niets zegt over uitgevoerde renovaties — dat bezwaar vervalt
 *     bij een geregistreerd label, want dat is gemeten ná die renovaties.
 *   - `building_type` volgt uit het geregistreerde woningtype. Dat kon de BAG níét: het
 *     gebruiksdoel onderscheidt alleen wonen van niet-wonen. Vandaar dat deze vraag nu
 *     twee bronnen accepteert.
 *
 * `skip_when_prefilled_by` mag daarom een lijst zijn: `building_type` vervalt zowel bij
 * een BAG-afleiding (`pdok`, het niet-woonfunctie geval) als bij een label (`epo`).
 *
 * Zonder label verandert er niets — registratie is verplicht bij verkoop, verhuur en
 * oplevering, dus de dekking is hoog maar niet volledig, en dan blijven beide vragen staan.
 *
 * Gepubliceerde v1–v7 blijven ongewijzigd (ADR-0001); alleen nieuwe intakes pinnen op v8.
 *
 * @return array<string, mixed>
 */

/** @var array<string, mixed> $config */
$config = require __DIR__.'/v7.php';

$config['version'] = 8;
$config['change_notes'] = 'EP-Online: isolatie-indicatie uit de energiebehoefte en bouwtype uit het geregistreerde woningtype. skip_when_prefilled_by accepteert nu meerdere bronnen.';

/** @var array<string, list<string>> $skipSources */
$skipSources = [
    'insulation_indication' => ['epo'],
    'building_type' => ['pdok', 'epo'],
];

/** @var list<array<string, mixed>> $sections */
$sections = $config['sections'];

foreach ($sections as $sectionIndex => $section) {
    /** @var list<array<string, mixed>> $questions */
    $questions = $section['questions'];

    foreach ($questions as $questionIndex => $question) {
        if (! isset($skipSources[$question['key']])) {
            continue;
        }

        $meta = is_array($question['meta'] ?? null) ? $question['meta'] : [];
        $meta['skip_when_prefilled_by'] = $skipSources[$question['key']];
        $questions[$questionIndex]['meta'] = $meta;
    }

    $sections[$sectionIndex]['questions'] = $questions;
}

$config['sections'] = $sections;

return $config;
