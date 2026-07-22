<?php

declare(strict_types=1);

/**
 * Airco intake template v6 — de vragenlijst gebruikt eindelijk de adaptieve motor.
 *
 * v1–v5 waren inhoudelijk een platte lijst: 38 vragen met precies één conditionele regel.
 * Alles wat de engine kan (rules, `skip_when_prefilled_by`, foto-afleiding) stond klaar maar
 * werd niet aangeroepen. v6 draait dat om, langs het vaste ontwerpprincipe uit AGENTS.md:
 * *niets vragen wat al bekend is of eenvoudiger kan worden vastgesteld*.
 *
 * De drie deltas t.o.v. v5:
 *
 *   1. **Foto's eerst.** Binnen `rooms` en `outdoor_unit` staat de foto vóór de vragen die
 *      hij beantwoordt. Daarvoor stonden ze erachter, waardoor de aanvrager eerst alles
 *      intypte en de foto daarna niets meer kon uitsparen.
 *   2. **Afleiden uit die foto's.** `room_photos` en `outdoor_location_photos` krijgen
 *      `meta.photo_analysis`; de vragen die daaruit volgen krijgen
 *      `meta.skip_when_prefilled_by = 'ai'`. Bij hoge zekerheid vervalt de vraag (bewijs
 *      blijft zichtbaar in het dossier), bij twijfel blijft het een bevestigbare voorzet.
 *   3. **Minder dubbele vrije tekst.** `access_notes` en `electrical_notes` vervallen;
 *      `additional_comments` aan het eind vangt dat op.
 *
 * Gepubliceerde v1–v5 blijven ongewijzigd (ADR-0001); alleen nieuwe intakes pinnen op v6.
 *
 * @return array<string, mixed>
 */

/** @var array<string, mixed> $config */
$config = require __DIR__.'/v5.php';

$config['version'] = 6;
$config['change_notes'] = 'Adaptieve vragenlijst: foto’s vóór de vragen die ze beantwoorden, afleiding van ruimte- en buitenunitkenmerken uit foto’s (skip bij hoge zekerheid, voorzet bij twijfel), bouwtype uit BAG-gebruiksdoel en samengevoegde vrije tekst.';

/**
 * Vragen die uit een foto volgen. Bij `prefill_source = 'ai'` (hoge zekerheid) verdwijnt de
 * stap; bij `ai_suggestion` blijft hij staan als voorzet.
 *
 * @var array<string, string>
 */
$derivedFromPhoto = [
    'room_size_indication' => 'ai',
    'sun_exposure' => 'ai',
    'glass_amount' => 'ai',
    'outdoor_mount_type' => 'ai',
    'outdoor_accessibility' => 'ai',
];

/**
 * Fotovragen die een analyseprofiel draaien. De profielnaam moet bestaan in
 * PhotoDerivationProfile::all(), anders faalt publiceren luidruchtig.
 *
 * @var array<string, string>
 */
$photoAnalysis = [
    'room_photos' => 'room',
    'outdoor_location_photos' => 'outdoor',
];

/**
 * Nieuwe volgorde binnen een sectie: de foto klimt naar voren, vóór alles wat hij kan
 * beantwoorden. Alleen secties die hier staan worden herordend.
 *
 * @var array<string, list<string>>
 */
$reordered = [
    'rooms' => [
        'room_type',
        'room_photos',
        'indoor_unit_position_photo',
        'room_size_indication',
        'sun_exposure',
        'glass_amount',
        'floor_level',
        'room_notes',
    ],
    'outdoor_unit' => [
        'outdoor_location',
        'outdoor_location_photos',
        'facade_overview_photo',
        'outdoor_mount_type',
        'outdoor_accessibility',
        'noise_sensitive',
    ],
];

/** @var list<string> $dropped */
$dropped = ['access_notes', 'electrical_notes'];

/** @var list<array<string, mixed>> $sections */
$sections = $config['sections'];

foreach ($sections as $sectionIndex => $section) {
    /** @var list<array<string, mixed>> $questions */
    $questions = $section['questions'];

    $questions = array_values(array_filter(
        $questions,
        static fn (array $question): bool => ! in_array($question['key'], $dropped, true),
    ));

    foreach ($questions as $questionIndex => $question) {
        $meta = is_array($question['meta'] ?? null) ? $question['meta'] : [];

        if (isset($photoAnalysis[$question['key']])) {
            $meta['photo_analysis'] = $photoAnalysis[$question['key']];
        }

        if (isset($derivedFromPhoto[$question['key']])) {
            $meta['skip_when_prefilled_by'] = $derivedFromPhoto[$question['key']];
            $questions[$questionIndex]['help_text'] = 'Staat er al een antwoord? Dan volgde dat uit je foto — controleer het even. De installateur beoordeelt de foto zelf ook.';
        }

        // BL-019 breder: het BAG-gebruiksdoel bepaalt het bouwtype al.
        if ($question['key'] === 'building_type') {
            $meta['skip_when_prefilled_by'] = 'pdok';
            $questions[$questionIndex]['help_text'] = 'Alleen nodig wanneer het gebruiksdoel niet eenduidig uit de BAG volgt.';
        }

        if ($meta !== []) {
            $questions[$questionIndex]['meta'] = $meta;
        }
    }

    if (isset($reordered[$section['key']])) {
        $order = array_flip($reordered[$section['key']]);

        usort($questions, static function (array $a, array $b) use ($order): int {
            return ($order[$a['key']] ?? PHP_INT_MAX) <=> ($order[$b['key']] ?? PHP_INT_MAX);
        });
    }

    foreach ($questions as $questionIndex => $question) {
        $questions[$questionIndex]['sort_order'] = $questionIndex + 1;
    }

    $sections[$sectionIndex]['questions'] = $questions;
}

$config['sections'] = $sections;

return $config;
