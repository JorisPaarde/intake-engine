<?php

declare(strict_types=1);

namespace App\Domains\AI\Support;

/**
 * Subject taxonomy for photo category checks (klanttest 2026-10-02).
 *
 * Expected subject comes from structured question keys / decision_area_key,
 * never from free-text prompt keyword heuristics.
 */
enum PhotoSubject: string
{
    case Room = 'room';
    case Fusebox = 'fusebox';
    case OutdoorUnit = 'outdoor_unit';
    case OutdoorLocation = 'outdoor_location';
    case PipeRoute = 'pipe_route';
    case Other = 'other';

    public function dutchLabel(): string
    {
        return match ($this) {
            self::Room => 'de hele ruimte',
            self::Fusebox => 'de meterkast',
            self::OutdoorUnit => 'de buitenunit',
            self::OutdoorLocation => 'de buitenplek voor de unit',
            self::PipeRoute => 'de leidingroute',
            self::Other => 'iets anders',
        };
    }

    public function dutchNoun(): string
    {
        return match ($this) {
            self::Room => 'een kamer-/ruimtefoto',
            self::Fusebox => 'een meterkastfoto',
            self::OutdoorUnit => 'een buitenunit',
            self::OutdoorLocation => 'een foto van de buitenplek',
            self::PipeRoute => 'een foto van de leidingroute',
            self::Other => 'een andere foto',
        };
    }

    /** Compact label for installer markers (“AI: lijkt meterkast, controleer”). */
    public function dutchShortLabel(): string
    {
        return match ($this) {
            self::Room => 'ruimte',
            self::Fusebox => 'meterkast',
            self::OutdoorUnit => 'buitenunit',
            self::OutdoorLocation => 'buitenplek',
            self::PipeRoute => 'leidingroute',
            self::Other => 'andere categorie',
        };
    }

    public static function tryFromMixed(mixed $value): ?self
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        return self::tryFrom($value);
    }

    /**
     * Expected subject for a template photo question / analysis profile.
     */
    public static function expectedForPhotoQuestion(string $questionKey, ?string $profileName = null): ?self
    {
        return match ($questionKey) {
            'room_photos', 'room_wall_outlet_photo' => self::Room,
            'fusebox_photo', 'fusebox_photo_extra' => self::Fusebox,
            'outdoor_location_photos' => self::OutdoorLocation,
            'pipe_route_photos' => self::PipeRoute,
            default => match ($profileName) {
                'room' => self::Room,
                'fusebox' => self::Fusebox,
                'outdoor' => self::OutdoorLocation,
                'pipe_route' => self::PipeRoute,
                default => null,
            },
        };
    }

    /**
     * Geaccepteerde onderwerpen voor een template-fotovraag (derive-pad).
     * Null = alleen subject_match van het model telt (strenge 1:1-check).
     *
     * Routevragen accepteren wand/plafond/goot/doorvoer én buitenunit-in-routecontext:
     * die beelden zijn bruikbaar voor de leidingroute en mogen de klant niet blokkeren.
     *
     * @return list<self>|null
     */
    public static function acceptedSubjectsForPhotoQuestion(string $questionKey, ?string $profileName = null): ?array
    {
        $expected = self::expectedForPhotoQuestion($questionKey, $profileName);

        if ($expected === self::PipeRoute) {
            return [
                self::PipeRoute,
                self::Room,
                self::OutdoorUnit,
                self::OutdoorLocation,
            ];
        }

        return null;
    }

    /**
     * Expected subject from a structured follow-up decision area.
     * Null → niet controleerbaar (placement, condensate, request, cost_risks, …).
     */
    public static function expectedFromDecisionArea(?string $decisionAreaKey): ?self
    {
        $accepted = self::acceptedSubjectsForDecisionArea($decisionAreaKey);

        return $accepted[0] ?? null;
    }

    /**
     * Geaccepteerde onderwerpen per follow-up decision area.
     * Null = gebied is niet controleerbaar (nooit wrong_subject).
     *
     * @return list<self>|null
     */
    public static function acceptedSubjectsForDecisionArea(?string $decisionAreaKey): ?array
    {
        if ($decisionAreaKey === null || $decisionAreaKey === '') {
            return null;
        }

        return match ($decisionAreaKey) {
            'power' => [self::Fusebox],
            // Routebewijs: goot/doorvoer/wand/plafond én buitenunit in routecontext.
            'refrigerant' => [self::PipeRoute, self::OutdoorUnit, self::Room, self::OutdoorLocation],
            'capacity' => [self::Room],
            // placement + condensate bewust niet controleerbaar.
            default => null,
        };
    }

    /**
     * Concrete customer message when the uploaded image does not match the ask.
     * Noemt altijd wat er wél nodig is (ontbrekend onderdeel).
     */
    public function mismatchMessage(self $detected): string
    {
        $needed = match ($this) {
            self::Fusebox => 'een foto van de meterkast (groepenkast open, recht van voren)',
            self::PipeRoute => 'een foto van de leidingroute (wand/plafond op de bedoelde plek, goot, leidingen of doorvoer)',
            self::Room => 'een foto van de hele ruimte vanuit de deuropening',
            self::OutdoorLocation => 'een foto van de buitenplek voor de unit',
            self::OutdoorUnit => 'een foto van de buitenunit',
            self::Other => 'een foto van '.$this->dutchLabel(),
        };

        if ($this === self::Fusebox) {
            return 'Vervang deze foto door '.$needed.'. Dit lijkt '.$detected->dutchNoun().'.';
        }

        return 'Dit is '.$detected->dutchNoun().'; we hebben '.$needed.' nodig.';
    }
}
