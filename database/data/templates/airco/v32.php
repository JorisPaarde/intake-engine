<?php

declare(strict_types=1);

/**
 * Airco template v32 — klant-hertest 13 nov (BL-166).
 *
 * - Huur: optionele vervolgvraag verhuurderstoestemming.
 * - Geluidsvraag: eigen helptekst (buren / slaapkamer).
 * - Binnenunit-voorbeeld per kamertype (geen “boven de bank” in een slaapkamer).
 * - Merkuitleg één keer (help_text leeg; bundelscherm houdt de uitleg).
 * - Stopcontact-interne instructies uit klantzichtbare wall_outlet-tekst.
 * - room_outlet_status niet meer shareable (blijft intern).
 *
 * Bouwt op v31. Gepubliceerde v1–v31 blijven ongewijzigd (ADR-0001).
 *
 * @return array<string, mixed>
 */

/** @var array<string, mixed> $config */
$config = require __DIR__.'/v31.php';

$config['version'] = 32;
$config['change_notes'] = 'BL-166: huur-verhuurderstoestemming; geluid-help; binnenunit-voorbeeld per kamertype; één merkuitleg; stopcontact-jargon uit klanttekst.';

/** @var list<array<string, mixed>> $sections */
$sections = $config['sections'];

foreach ($sections as $sectionIndex => $section) {
    /** @var list<array<string, mixed>> $questions */
    $questions = $section['questions'] ?? [];
    $sectionKey = $section['key'] ?? null;

    if ($sectionKey === 'building') {
        $hasLandlord = false;
        foreach ($questions as $question) {
            if (($question['key'] ?? null) === 'landlord_permission') {
                $hasLandlord = true;
                break;
            }
        }

        if (! $hasLandlord) {
            $insertAt = count($questions);
            foreach ($questions as $questionIndex => $question) {
                if (($question['key'] ?? null) === 'ownership') {
                    $insertAt = $questionIndex + 1;
                    break;
                }
            }

            array_splice($questions, $insertAt, 0, [[
                'key' => 'landlord_permission',
                'type' => 'single_choice',
                'label' => 'Heeft je verhuurder toestemming gegeven of weet die van de plannen?',
                'help_text' => 'Bij een huurwoning is vaak toestemming nodig vóór plaatsing. Dit is optioneel; je mag “Nog niet gevraagd” kiezen. Je installateur ziet je antwoord in het overzicht.',
                'is_required' => false,
                'sort_order' => 0,
                'options' => [
                    ['value' => 'yes', 'label' => 'Ja', 'sort_order' => 1],
                    ['value' => 'no', 'label' => 'Nee', 'sort_order' => 2],
                    ['value' => 'not_asked', 'label' => 'Nog niet gevraagd', 'sort_order' => 3],
                ],
                'rules' => [[
                    'source_question_key' => 'ownership',
                    'operator' => 'equals',
                    'value' => ['value' => 'rented'],
                    'effect' => 'show',
                ]],
            ]]);
        }
    }

    foreach ($questions as $questionIndex => $question) {
        $key = $question['key'] ?? null;
        if (! is_string($key)) {
            continue;
        }

        if ($key === 'noise_sensitive') {
            $questions[$questionIndex]['label'] = 'Is de omgeving geluidsgevoelig?';
            $questions[$questionIndex]['help_text'] = 'Denk aan buren dichtbij, een slaapkamer naast de buitenunit, of een stil hofje. Dat helpt bij de plek van de buitenunit.';
        }

        if ($key === 'preferred_indoor_location') {
            $questions[$questionIndex]['help_text'] = 'Bijvoorbeeld “boven de bank aan de buitenmuur”. Geen idee? Gebruik dan de knop hieronder.';
            $meta = is_array($question['meta'] ?? null) ? $question['meta'] : [];
            $meta['help_by_room_type'] = [
                'bedroom' => 'Bijvoorbeeld “boven het bed aan de buitenmuur” of “hoog aan de muur tegenover het bed”.',
                'living_room' => 'Bijvoorbeeld “boven de bank aan de buitenmuur”.',
                'office' => 'Bijvoorbeeld “hoog aan de muur boven het bureau”.',
                'attic' => 'Bijvoorbeeld “hoog aan de rechte muur, niet onder het schuine dak”.',
                'kitchen' => 'Bijvoorbeeld “hoog aan de buitenmuur, weg van het fornuis”.',
                'other' => 'Noem een herkenbare plek aan een buitenmuur, bijvoorbeeld “hoog naast het raam”.',
            ];
            $questions[$questionIndex]['meta'] = $meta;
        }

        if ($key === 'brand_preference') {
            // Uitleg staat één keer op het closing_wishes-scherm (step-builder description).
            $questions[$questionIndex]['help_text'] = null;
        }

        if ($key === 'wall_outlet_photo') {
            $questions[$questionIndex]['label'] = 'Extra foto van de wand met stopcontact';
            $questions[$questionIndex]['help_text'] = 'Op de eerdere foto was geen stopcontact duidelijk te zien. Maak één duidelijke foto van een wand met stopcontact.';
            $questions[$questionIndex]['photo_instructions'] = 'Fotografeer de wand met stopcontact recht van voren.';
        }

        if ($key === 'room_photos') {
            $instructions = (string) ($question['photo_instructions'] ?? '');
            if (str_contains(mb_strtolower($instructions), 'stopcontact')) {
                $questions[$questionIndex]['photo_instructions'] = 'Maak een overzichtsfoto vanuit de deuropening. Laat wanden, ramen en deuren zien.';
            }
            $help = $question['help_text'] ?? null;
            if (is_string($help) && str_contains(mb_strtolower($help), 'stopcontact')) {
                $questions[$questionIndex]['help_text'] = 'Eén duidelijke overzichtsfoto helpt.';
            }
        }

        if ($key === 'room_outlet_status') {
            $meta = is_array($question['meta'] ?? null) ? $question['meta'] : [];
            unset(
                $meta['share_across_instances'],
                $meta['share_section_title'],
                $meta['share_differs_label'],
                $meta['share_label'],
                $meta['share_help_text'],
            );
            $questions[$questionIndex]['meta'] = $meta;
            // Interne statusvraag: nooit klanttekst over “je ziet deze vraag normaal niet”.
            $questions[$questionIndex]['help_text'] = null;
        }
    }

    foreach ($questions as $questionIndex => $question) {
        $questions[$questionIndex]['sort_order'] = $questionIndex + 1;
    }

    $sections[$sectionIndex]['questions'] = $questions;
}

$config['sections'] = $sections;

return $config;
