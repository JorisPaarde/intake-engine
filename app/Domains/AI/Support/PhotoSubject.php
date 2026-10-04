<?php

declare(strict_types=1);

namespace App\Domains\AI\Support;

use App\Domains\Intake\Models\ContributionTask;

/**
 * Subject taxonomy for photo category checks (klanttest 2026-10-02).
 *
 * Expected subject comes from structured question keys / decision_area_key /
 * contribution-task metadata, never from free-text prompt keyword heuristics.
 */
enum PhotoSubject: string
{
    case Room = 'room';
    case Fusebox = 'fusebox';
    case OutdoorUnit = 'outdoor_unit';
    case OutdoorLocation = 'outdoor_location';
    case PipeRoute = 'pipe_route';
    case IndoorUnit = 'indoor_unit';
    case Other = 'other';

    public function dutchLabel(): string
    {
        return match ($this) {
            self::Room => 'de hele ruimte',
            self::Fusebox => 'de meterkast',
            self::OutdoorUnit => 'de buitenunit',
            self::OutdoorLocation => 'de buitenplek voor de unit',
            self::PipeRoute => 'de leidingroute',
            self::IndoorUnit => 'de binnenunit',
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
            self::IndoorUnit => 'een binnenunit',
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
            self::IndoorUnit => 'binnenunit',
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
            'room_photos', 'room_wall_outlet_photo', 'wall_outlet_photo' => self::Room,
            'indoor_unit_position_photo' => self::Room,
            'fusebox_photo', 'fusebox_photo_extra' => self::Fusebox,
            'outdoor_location_photos', 'around_house_photos' => self::OutdoorLocation,
            'pipe_route_photos' => self::PipeRoute,
            'drain_photo' => self::OutdoorLocation,
            default => match ($profileName) {
                'room', 'wall_outlet', 'indoor_position' => self::Room,
                'fusebox' => self::Fusebox,
                'outdoor', 'around_house', 'drain' => self::OutdoorLocation,
                'pipe_route' => self::PipeRoute,
                default => null,
            },
        };
    }

    /**
     * Geaccepteerde onderwerpen voor een template-fotovraag (derive-pad).
     * Null = alleen subject_match van het model telt (strenge 1:1-check).
     *
     * Routevragen accepteren wand/plafond/goot/doorvoer én unit-in-routecontext:
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
                self::IndoorUnit,
                self::OutdoorUnit,
                self::OutdoorLocation,
            ];
        }

        if ($expected === self::OutdoorLocation) {
            return [
                self::OutdoorLocation,
                self::OutdoorUnit,
            ];
        }

        if ($questionKey === 'wall_outlet_photo' || $profileName === 'wall_outlet') {
            return [self::Room];
        }

        if ($questionKey === 'indoor_unit_position_photo' || $profileName === 'indoor_position') {
            return [self::Room, self::IndoorUnit];
        }

        if ($questionKey === 'drain_photo' || $profileName === 'drain') {
            return [self::OutdoorLocation, self::OutdoorUnit, self::PipeRoute];
        }

        return null;
    }

    /**
     * Expected subject from a structured follow-up decision area.
     * Null → niet controleerbaar (placement zonder taakmeta, condensate, request, …).
     */
    public static function expectedFromDecisionArea(?string $decisionAreaKey): ?self
    {
        $accepted = self::acceptedSubjectsForDecisionArea($decisionAreaKey);

        return $accepted[0] ?? null;
    }

    /**
     * Geaccepteerde onderwerpen per follow-up decision area.
     * Null = gebied is niet controleerbaar (nooit wrong_subject) — tenzij taakmeta
     * een verwacht onderwerp zet via {@see acceptedSubjectsForTask()}.
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
            // Routebewijs: goot/doorvoer/wand/plafond én unit in routecontext.
            'refrigerant' => [
                self::PipeRoute,
                self::IndoorUnit,
                self::OutdoorUnit,
                self::Room,
                self::OutdoorLocation,
            ],
            'capacity' => [self::Room],
            // placement + condensate bewust niet globaal controleerbaar —
            // gevel-/rondom-huis-taken zetten expected/accepted in task meta.
            default => null,
        };
    }

    /**
     * Structured expected/accepted subjects from contribution-task metadata.
     * Falls back to decision_area_key when meta is absent.
     *
     * @return list<self>|null
     */
    public static function acceptedSubjectsForTask(?ContributionTask $task): ?array
    {
        if ($task === null) {
            return null;
        }

        $fromMeta = self::subjectsFromTaskMeta($task->meta);
        if ($fromMeta !== null) {
            return $fromMeta;
        }

        return self::acceptedSubjectsForDecisionArea($task->decision_area_key);
    }

    public static function expectedForTask(?ContributionTask $task): ?self
    {
        $accepted = self::acceptedSubjectsForTask($task);

        return $accepted[0] ?? null;
    }

    /**
     * @param  array<string, mixed>|null  $meta
     * @return list<self>|null
     */
    public static function subjectsFromTaskMeta(?array $meta): ?array
    {
        if ($meta === null) {
            return null;
        }

        $acceptedRaw = $meta['accepted_photo_subjects'] ?? null;
        if (is_array($acceptedRaw) && $acceptedRaw !== []) {
            $subjects = [];
            foreach ($acceptedRaw as $value) {
                $subject = self::tryFromMixed($value);
                if ($subject instanceof self) {
                    $subjects[] = $subject;
                }
            }

            return $subjects !== [] ? array_values(array_unique($subjects, SORT_REGULAR)) : null;
        }

        $expected = self::tryFromMixed($meta['expected_photo_subject'] ?? null);

        return $expected instanceof self ? [$expected] : null;
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
            self::OutdoorLocation => 'een foto van de gevel, tuin of buitenplek voor de unit (zonder bestaande airco is prima)',
            self::OutdoorUnit => 'een foto van de buitenunit',
            self::IndoorUnit => 'een foto van de binnenunit of de wandplek',
            self::Other => 'een foto van '.$this->dutchLabel(),
        };

        if ($this === self::Fusebox) {
            return 'Vervang deze foto door '.$needed.'. Dit lijkt '.$detected->dutchNoun().'.';
        }

        if ($this === self::OutdoorLocation) {
            return 'Dit is '.$detected->dutchNoun().'; we hebben '.$needed.' nodig.';
        }

        return 'Dit is '.$detected->dutchNoun().'; we hebben '.$needed.' nodig.';
    }

    /**
     * Action-oriented customer retake prompt (je/jouw). Installer diagnosis stays separate.
     */
    public function customerRetakePrompt(): string
    {
        return match ($this) {
            self::Fusebox => 'Maak een nieuwe, duidelijke foto van je meterkast',
            self::Room => 'Maak een nieuwe, duidelijke foto van de hele ruimte',
            self::OutdoorUnit => 'Maak een nieuwe, duidelijke foto van de buitenunit',
            self::OutdoorLocation => 'Maak een nieuwe, duidelijke foto van de gevel, tuin of buitenplek',
            self::PipeRoute => 'Maak een nieuwe, duidelijke foto van de leidingroute',
            self::IndoorUnit => 'Maak een nieuwe, duidelijke foto van de binnenunit of wandplek',
            self::Other => 'Maak een nieuwe, duidelijke foto van wat we vroegen',
        };
    }

    /** Detect installer-only mismatch blockers that must not be shown to the customer. */
    public static function isInstallerMismatchReason(string $text): bool
    {
        $lower = mb_strtolower($text);

        return str_contains($lower, 'handmatig controleren')
            || str_contains($lower, 'ontvangen foto lijkt');
    }
}
