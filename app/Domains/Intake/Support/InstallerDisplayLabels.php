<?php

declare(strict_types=1);

namespace App\Domains\Intake\Support;

/**
 * Centrale Nederlandse labels voor interne enum-/bronwaarden in de installateurswerkplek.
 * Ruwe keys (short, derived_lxw, high, …) horen niet in de UI.
 */
final class InstallerDisplayLabels
{
    /** @var array<string, string> */
    private const LENGTH_CLASS = [
        'short' => 'Kort',
        'medium' => 'Middel',
        'long' => 'Lang',
        'unknown' => 'Onbekend',
    ];

    /** @var array<string, string> */
    private const COST_IMPACT = [
        'low' => 'Laag',
        'medium' => 'Middel',
        'high' => 'Hoog',
        'unknown' => 'Onbekend',
    ];

    /** @var array<string, string> */
    private const CONFIDENCE = [
        'high' => 'hoge',
        'medium' => 'middelmatige',
        'low' => 'lage',
    ];

    /** @var array<string, string> */
    private const SOURCE = [
        'installer' => 'installateur',
        'customer' => 'klant',
        'ai' => 'AI',
        'ai_text' => 'AI (tekst)',
        'ai_photo' => 'AI (foto)',
        'ai_text_suggestion' => 'AI-voorstel (tekst)',
        'ai_photo_suggestion' => 'AI-voorstel (foto)',
        'ai_suggestion' => 'AI-voorstel',
        'request_text' => 'aanvraagtekst',
        'derived_lxw' => 'berekend uit L×B',
        'template_bridge' => 'uit aanvraag overgenomen',
        'external' => 'externe bron',
    ];

    /**
     * Alle bekende interne waarden die in de installateurs-UI mogen voorkomen.
     * Tests falen als een waarde ontbreekt of gelijk is aan de ruwe key.
     *
     * @return array<string, array<string, string>>
     */
    public static function maps(): array
    {
        return [
            'length_class' => self::LENGTH_CLASS,
            'cost_impact' => self::COST_IMPACT,
            'confidence' => self::CONFIDENCE,
            'source' => self::SOURCE,
        ];
    }

    public static function lengthClass(?string $value): ?string
    {
        return self::lookup(self::LENGTH_CLASS, $value);
    }

    public static function costImpact(?string $value): ?string
    {
        return self::lookup(self::COST_IMPACT, $value);
    }

    public static function confidence(?string $value): ?string
    {
        return self::lookup(self::CONFIDENCE, $value);
    }

    public static function source(?string $value): ?string
    {
        return self::lookup(self::SOURCE, $value);
    }

    /**
     * @param  array<string, string>  $map
     */
    private static function lookup(array $map, ?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $map[$value] ?? $value;
    }

    /**
     * True when the raw value has a Dutch label different from itself.
     */
    public static function isTranslated(string $group, string $value): bool
    {
        $map = self::maps()[$group] ?? null;
        if ($map === null || ! array_key_exists($value, $map)) {
            return false;
        }

        return $map[$value] !== $value;
    }
}
