<?php

declare(strict_types=1);

/**
 * Airco intake template v3 — prefill van bekende gegevens (BL-016).
 *
 * v3 = v2 + twee deterministische prefill-vlaggen op vraag-`meta` (geen inhoudelijke
 * vraagwijzigingen). Gepubliceerde v1/v2 blijven ongewijzigd voor lopende/afgeronde
 * intakes (ADR-0001); nieuwe intakes pinnen op v3.
 *
 *   - `meta.installer_prefillable = true`  → de installateur kan de vraag bij het
 *     aanmaken alvast beantwoorden; de aanvrager ziet het als voorzet en bevestigt.
 *   - `meta.prefill_from_previous = true`  → binnen een repeatable sectie wordt het
 *     antwoord van de vorige instantie als voorzet aangeboden (aanvrager bevestigt).
 *
 * Prefill is altijd een *voorzet*, nooit een verborgen aanname (zie docs/intake-engine.md).
 * Dit bestand bouwt bewust voort op v2 zodat de vragenset niet uiteen kan lopen; de
 * enige v3-delta staat hieronder expliciet.
 *
 * @return array{
 *     key: string,
 *     name: string,
 *     description: string,
 *     version: int,
 *     change_notes: string,
 *     sections: list<array<string, mixed>>
 * }
 */

/** @var array<string, mixed> $config */
$config = require __DIR__.'/v2.php';

$config['version'] = 3;
$config['change_notes'] = 'BL-016: prefill-vlaggen op vraag-meta — installateur kan aanvraagvragen alvast invullen (installer_prefillable) en ruimtevragen nemen een voorzet van de vorige ruimte over (prefill_from_previous). Geen inhoudelijke vraagwijzigingen t.o.v. v2.';

// v3-delta — expliciet, zodat de bedoeling leesbaar blijft:
$installerPrefillable = ['request_reason', 'cooling_heating', 'indoor_unit_count', 'brand_preference', 'desired_planning'];
$prefillFromPrevious = ['floor_level'];

/** @var list<array<string, mixed>> $sections */
$sections = $config['sections'];

foreach ($sections as $sIndex => $section) {
    /** @var list<array<string, mixed>> $questions */
    $questions = $section['questions'];

    foreach ($questions as $qIndex => $question) {
        $meta = is_array($question['meta'] ?? null) ? $question['meta'] : [];

        if ($section['key'] === 'request' && in_array($question['key'], $installerPrefillable, true)) {
            $meta['installer_prefillable'] = true;
        }

        if ($section['key'] === 'rooms' && in_array($question['key'], $prefillFromPrevious, true)) {
            $meta['prefill_from_previous'] = true;
        }

        if ($meta !== []) {
            $questions[$qIndex]['meta'] = $meta;
        }
    }

    $sections[$sIndex]['questions'] = $questions;
}

$config['sections'] = $sections;

return $config;
