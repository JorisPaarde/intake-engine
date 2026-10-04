<?php

declare(strict_types=1);

namespace App\Domains\Intake\Support;

use App\Domains\Intake\Models\Intake;
use App\Enums\PhotoUsabilityVerdict;

/**
 * Leesbare AI-voorsteltekst voor installateurs: veldlabel + waarde + concrete onzekerheid.
 * Geen raw keys in UI.
 */
final class TechnicalProposalCopy
{
    /**
     * Concrete onzekerheidstekst bij een open technisch voorstel.
     */
    public static function uncertainty(Intake $intake, string $questionKey, string $confidence = 'middel'): string
    {
        if ($questionKey === 'free_group_known') {
            $intake->loadMissing(['uploads', 'externalFacts']);
            $fuseboxUploads = $intake->uploads->filter(
                static fn ($upload): bool => in_array($upload->question_key, ['fusebox_photo', 'fusebox_photo_extra'], true),
            );

            foreach ($fuseboxUploads as $upload) {
                $verdict = $upload->usability_verdict;
                if ($verdict === PhotoUsabilityVerdict::TooSmall) {
                    return 'Niet zeker: foto te klein om het aantal vrije groepen te zien';
                }
                if ($verdict === PhotoUsabilityVerdict::TooDark) {
                    return 'Niet zeker: foto te donker om vrije groepen te beoordelen';
                }
            }

            $fact = $intake->externalFacts->first(
                static fn ($row): bool => $row->fact_key === 'fusebox_photo_assessment',
            );
            $emptySpace = is_array($fact?->value) ? ($fact->value['empty_module_space'] ?? null) : null;
            if ($emptySpace === 'unknown' || $emptySpace === null) {
                return 'Niet zeker: uit de meterkastfoto is nog niet betrouwbaar af te leiden of er vrije groepen zijn';
            }
            if ($emptySpace === 'none_visible') {
                return 'Niet zeker: geen vrije moduleplek zichtbaar — mogelijk groepenkast uitbreiden of vervangen';
            }
        }

        return $confidence === 'hoog'
            ? 'Nog te bevestigen door de installateur'
            : 'Nog te beoordelen door de installateur';
    }

    /**
     * Fallback veldlabel wanneer de templatevraag ontbreekt (nooit de raw key tonen).
     */
    public static function fallbackFieldLabel(string $questionKey): string
    {
        return match ($questionKey) {
            'free_group_known' => 'Is er al een aparte vrije stroomgroep beschikbaar?',
            'natural_fall_possible' => 'Kan het condenswater waarschijnlijk zonder pomp weglopen?',
            'pipe_route_description' => 'Hoe lopen de leidingen naar buiten?',
            'pipe_distance_indication' => 'Hoe lang is de leidingroute ongeveer?',
            'drillings_needed' => 'Zijn er doorboringen door muren of vloeren nodig?',
            default => 'Technisch punt',
        };
    }
}
