<?php

declare(strict_types=1);

/**
 * Airco template v34 — eenvoudige klantopname (BL-172).
 *
 * De klant ziet nog maar vier soorten schermen:
 * 1. Wat wil je laten doen? (vrije tekst; AI haalt ruimtes, maten en koelen/verwarmen eruit)
 * 2. Per ruimte één foto-scherm, met daarbij de vraag aan welke kant van het huis de ruimte ligt
 * 3. Foto van de plek voor de buitenunit
 * 4. Foto van de meterkast
 * Daarna één open wensenveld met voorbeeldvragen (alleen voor wat nog onbekend is) en versturen.
 *
 * Alle andere vragen blijven in het template, met `audience: installer`: AI en externe bronnen
 * (BAG, energielabel, 3DBAG, luchtfoto) mogen ze vullen, de installateur ziet ze in het dossier
 * en kan ze via een vervolgvraag alsnog aan de klant stellen. Niets wordt weggegooid.
 *
 * Bouwt op v33. Gepubliceerde v1–v33 blijven ongewijzigd (ADR-0001).
 *
 * @return array<string, mixed>
 */

/** @var array<string, mixed> $config */
$config = require __DIR__.'/v33.php';

$config['version'] = 34;
$config['change_notes'] = 'BL-172: eenvoudige klantopname — aanvraagtekst, kamerfoto + gevelkant, buitenunitfoto, meterkast, wensen; overige vragen voor de installateur.';

/** Vragen die de klant in de eenvoudige opname nog als eigen scherm ziet. */
$customerKeys = [
    'request_reason',
    'indoor_unit_count',
    'room_photos',
    'outdoor_location_photos',
    'fusebox_photo',
    'additional_comments',
    'truth_confirmation',
    'privacy_consent',
];

/** @var list<array<string, mixed>> $sections */
$sections = $config['sections'];

foreach ($sections as $sectionIndex => $section) {
    $sectionKey = $section['key'] ?? null;

    /** @var list<array<string, mixed>> $questions */
    $questions = $section['questions'] ?? [];

    foreach ($questions as $questionIndex => $question) {
        $key = $question['key'] ?? null;
        if (! is_string($key)) {
            continue;
        }

        $meta = is_array($question['meta'] ?? null) ? $question['meta'] : [];

        if (! in_array($key, $customerKeys, true)) {
            // Blijft beschikbaar voor AI, dossier en vervolgvragen; geen klantstap.
            $meta['audience'] = 'installer';
        }

        if ($key === 'request_reason') {
            $questions[$questionIndex]['label'] = 'Wat wil je laten doen?';
            $questions[$questionIndex]['help_text'] = 'Vertel het in je eigen woorden. Bijvoorbeeld: in welke ruimtes, hoe groot ongeveer, en of je wilt koelen, verwarmen of allebei.';
            $meta['customer_flow'] = 'simple';
            $meta['placeholder'] = 'Bijv. airco in 3 slaapkamers van ongeveer 3 bij 5 meter, koelen en verwarmen';
            $meta['dictation_hint'] = true;
        }

        if ($key === 'room_photos') {
            $questions[$questionIndex]['label'] = 'Foto van de ruimte';
            $questions[$questionIndex]['photo_instructions'] = 'Maak een foto vanuit de deuropening. Zorg dat de muur waar de airco mag komen goed te zien is.';
            $questions[$questionIndex]['help_text'] = 'Eén duidelijke foto is genoeg. Meer mag ook.';
        }

        if ($key === 'outdoor_location_photos') {
            $questions[$questionIndex]['label'] = 'Foto van de plek voor de buitenunit';
            $questions[$questionIndex]['help_text'] = 'Bijvoorbeeld de gevel, de tuin, het balkon of het dak. Weet je het niet zeker? Maak dan een foto van de buitenkant aan de kant van de ruimtes.';
        }

        if ($key === 'fusebox_photo') {
            $questions[$questionIndex]['label'] = 'Foto van de meterkast';
            $questions[$questionIndex]['help_text'] = 'Open het deurtje en fotografeer de groepenkast met alle schakelaars.';
        }

        if ($key === 'additional_comments') {
            $questions[$questionIndex]['label'] = 'Heb je nog wensen?';
            $questions[$questionIndex]['help_text'] = null;
            $meta['dictation_hint'] = true;
            // Voorbeeldvragen tonen alleen zolang het genoemde onderwerp nog onbekend is.
            $meta['wish_prompts_max'] = 4;
            $meta['wish_prompts'] = [
                ['when_missing' => 'cooling_heating', 'text' => 'Wil je alleen koelen, of ook verwarmen met de airco?'],
                ['when_missing' => 'app_control_wanted', 'text' => 'Wil je de airco ook met een app op je telefoon bedienen?'],
                ['when_missing' => 'preferred_indoor_location', 'text' => 'Waar wil je de binnenunit het liefst? Bijvoorbeeld boven de deur of naast het raam.'],
                ['when_missing' => 'noise_sensitive', 'text' => 'Is stilte belangrijk? Bijvoorbeeld in een slaapkamer, of met buren dichtbij.'],
                ['when_missing' => 'desired_planning', 'text' => 'Wanneer wil je dat de airco er hangt?'],
                ['when_missing' => 'brand_preference', 'text' => 'Heb je een voorkeur voor een merk?'],
                ['when_missing' => 'ownership', 'text' => 'Is het een koop- of huurwoning?'],
            ];
        }

        $questions[$questionIndex]['meta'] = $meta === [] ? null : $meta;
    }

    if ($sectionKey === 'rooms') {
        $photoIndex = null;
        foreach ($questions as $questionIndex => $question) {
            if (($question['key'] ?? null) === 'room_photos') {
                $photoIndex = $questionIndex;
                break;
            }
        }

        // Op hetzelfde scherm als de kamerfoto (companion), nooit een eigen stap.
        // Samen met de luchtfoto (noord-boven) helpt dit de installateur de zonkant te bepalen.
        $facadeSide = [
            'key' => 'room_facade_side',
            'type' => 'single_choice',
            'label' => 'Aan welke kant van het huis ligt deze ruimte?',
            'is_required' => false,
            'sort_order' => 0,
            'options' => [
                ['value' => 'front', 'label' => 'Voorkant (straatkant)', 'sort_order' => 1],
                ['value' => 'back', 'label' => 'Achterkant', 'sort_order' => 2],
                ['value' => 'side', 'label' => 'Zijkant', 'sort_order' => 3],
                ['value' => 'unknown', 'label' => 'Weet ik niet', 'sort_order' => 4],
            ],
            'meta' => [
                'companion_of' => 'room_photos',
                'installer_prefillable' => true,
            ],
        ];

        $insertAt = $photoIndex === null ? 0 : $photoIndex + 1;
        array_splice($questions, $insertAt, 0, [$facadeSide]);
    }

    if ($sectionKey === 'closing') {
        $wishIndex = null;
        foreach ($questions as $questionIndex => $question) {
            if (($question['key'] ?? null) === 'additional_comments') {
                $wishIndex = $questionIndex;
                break;
            }
        }

        // Geen klantstap: AI vult dit uit de aanvraag- of wensentekst.
        $appControl = [
            'key' => 'app_control_wanted',
            'type' => 'single_choice',
            'label' => 'Wil de klant de airco met een app bedienen?',
            'is_required' => false,
            'sort_order' => 0,
            'options' => [
                ['value' => 'yes', 'label' => 'Ja', 'sort_order' => 1],
                ['value' => 'no', 'label' => 'Nee', 'sort_order' => 2],
                ['value' => 'unknown', 'label' => 'Weet ik niet', 'sort_order' => 3],
            ],
            'meta' => [
                'audience' => 'installer',
                'installer_prefillable' => true,
            ],
        ];

        $insertAt = $wishIndex === null ? 0 : $wishIndex;
        array_splice($questions, $insertAt, 0, [$appControl]);
    }

    foreach ($questions as $questionIndex => $question) {
        $questions[$questionIndex]['sort_order'] = $questionIndex + 1;
    }

    $sections[$sectionIndex]['questions'] = $questions;
}

$config['sections'] = $sections;

return $config;
