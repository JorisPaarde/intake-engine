<?php

declare(strict_types=1);

/**
 * Airco intake template v10 — een onbruikbare foto houdt de aanvrager tegen.
 *
 * Tot v9 was een negatief foto-oordeel een vrijblijvende hint: je liep er zo langs, en de
 * installateur moest er later een aanvullingsronde voor openen. Dat is precies de extra
 * handeling die het hoofddoel wil vermijden — alleen dan bij de verkeerde partij.
 *
 * `meta.blocking_photo_quality` zet dat om in een blokkade op **Volgende**, met de concrete
 * verbeterinstructie uit de beoordeling erbij. Verwijderen en opnieuw uploaden, of een
 * extra foto toevoegen, heft hem op.
 *
 * Bewust opt-in per vraag, niet overal:
 *
 *   - `room_photos`, `outdoor_location_photos` en `fusebox_photo` gaan over één duidelijk
 *     onderwerp dat in één goede opname past. Daar is "de foto is niet goed genoeg" een
 *     verwijt dat de aanvrager kán oplossen.
 *   - `pipe_route_photos` juist niet. Een leidingroute loopt door het huis en is per
 *     definitie niet in één beeld te vangen; het profiel beoordeelt of de route te vólgen
 *     is over meerdere opnames. Een negatief oordeel daar zou de aanvrager vastzetten op
 *     iets wat hij niet kan verhelpen. Vandaar dat dit profiel meer foto's meestuurt
 *     (`PhotoDerivationProfile::maxImages`) maar niet blokkeert.
 *   - `indoor_unit_position_photo` en `drain_photo` hebben geen analyseprofiel en dus geen
 *     oordeel om op te blokkeren.
 *
 * Een ontbrekend oordeel blokkeert nooit: staat de foto-analyse uit of faalt de provider,
 * dan loopt de aanvrager gewoon door.
 *
 * Gepubliceerde v1–v9 blijven ongewijzigd (ADR-0001); alleen nieuwe intakes pinnen op v10.
 *
 * @return array<string, mixed>
 */

/** @var array<string, mixed> $config */
$config = require __DIR__.'/v9.php';

$config['version'] = 10;
$config['change_notes'] = 'Een onbruikbaar beoordeelde foto blokkeert Volgende met een concrete verbeterinstructie, voor ruimte-, buitenunit- en meterkastfoto’s. De leidingroute blokkeert bewust niet.';

/** @var list<string> $blocking */
$blocking = ['room_photos', 'outdoor_location_photos', 'fusebox_photo'];

/** @var list<array<string, mixed>> $sections */
$sections = $config['sections'];

foreach ($sections as $sectionIndex => $section) {
    /** @var list<array<string, mixed>> $questions */
    $questions = $section['questions'];

    foreach ($questions as $questionIndex => $question) {
        if (! in_array($question['key'], $blocking, true)) {
            continue;
        }

        $meta = is_array($question['meta'] ?? null) ? $question['meta'] : [];
        $meta['blocking_photo_quality'] = true;
        $questions[$questionIndex]['meta'] = $meta;
    }

    $sections[$sectionIndex]['questions'] = $questions;
}

$config['sections'] = $sections;

return $config;
