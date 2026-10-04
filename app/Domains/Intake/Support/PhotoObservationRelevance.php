<?php

declare(strict_types=1);

namespace App\Domains\Intake\Support;

use App\Domains\Intake\Models\AircoPlacementOption;
use App\Domains\Intake\Models\DossierRecord;
use App\Domains\Intake\Models\Intake;
use App\Enums\AircoPlacementType;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Filtert AI-fotonotities voor de installateurswerkplek: alleen relevante notities
 * t.o.v. de gekozen oplossing, en alleen een fototaak bij concreet ontbrekende info.
 */
final class PhotoObservationRelevance
{
    /**
     * @param  Collection<int, DossierRecord>  $suggestions
     * @return Collection<int, DossierRecord>
     */
    public function filterForDisplay(Intake $intake, Collection $suggestions): Collection
    {
        if (! $intake->relationLoaded('aircoPlacements')) {
            $intake->loadMissing('aircoPlacements');
        }

        return $suggestions
            ->filter(fn (DossierRecord $record): bool => $this->isRelevantToSolution($intake, $record))
            ->values();
    }

    public function isRelevantToSolution(Intake $intake, DossierRecord $suggestion): bool
    {
        $text = $this->text($suggestion);
        if ($text === '') {
            return false;
        }

        $lower = Str::lower($text);

        // Floor-model / console risks are irrelevant when a wall unit is already planned.
        if ($this->mentionsFloorModel($lower) && $this->hasWallMountedIndoorPlan($intake)) {
            return false;
        }

        return true;
    }

    /**
     * Only offer "Vraag nieuwe foto" when the note points to concrete missing
     * information that can change a technical decision — not for already-visible features.
     */
    public function warrantsPhotoTask(DossierRecord $suggestion): bool
    {
        $text = $this->text($suggestion);
        if ($text === '') {
            return false;
        }

        $lower = Str::lower($text);

        // Positive / already-visible observations: glass, radiator, wall, etc.
        if ($this->describesVisibleFeatureWithoutGap($lower)) {
            return false;
        }

        return $this->signalsMissingOrUnclearEvidence($lower);
    }

    private function text(DossierRecord $suggestion): string
    {
        $text = $suggestion->value['text'] ?? null;

        return is_string($text) ? trim($text) : '';
    }

    private function mentionsFloorModel(string $lower): bool
    {
        return Str::contains($lower, [
            'vloermodel',
            'vloerunit',
            'vloer-model',
            'console-unit',
            'console unit',
            'floor model',
            'floor-standing',
            'staand model',
            'vrijstaand model',
        ]);
    }

    private function hasWallMountedIndoorPlan(Intake $intake): bool
    {
        return $intake->aircoPlacements->contains(
            function (AircoPlacementOption $placement): bool {
                if ($placement->type !== AircoPlacementType::IndoorUnit) {
                    return false;
                }

                $haystack = Str::lower(trim(
                    ($placement->label ?? '').' '.($placement->description ?? ''),
                ));

                return Str::contains($haystack, [
                    'wand',
                    'muur',
                    'boven de deur',
                    'boven deur',
                    'hoog',
                    'plafond',
                    'wall',
                ]) || ! Str::contains($haystack, ['vloer', 'console', 'staand']);
            },
        );
    }

    private function describesVisibleFeatureWithoutGap(string $lower): bool
    {
        $visibleFeatures = [
            'glaspartij',
            'glas',
            'raam',
            'ramen',
            'radiator',
            'radiatoren',
            'cv-radiator',
            'zichtbaar',
            'te zien',
            'aanwezig',
            'herkenbaar',
        ];

        $hasVisibleFeature = Str::contains($lower, $visibleFeatures);
        if (! $hasVisibleFeature) {
            return false;
        }

        return ! $this->signalsMissingOrUnclearEvidence($lower);
    }

    private function signalsMissingOrUnclearEvidence(string $lower): bool
    {
        return Str::contains($lower, [
            'niet zichtbaar',
            'niet te zien',
            'ontbreekt',
            'ontbrekend',
            'onduidelijk',
            'onleesbaar',
            'niet leesbaar',
            'niet te beoordelen',
            'meer detail',
            'betere foto',
            'nieuwe foto',
            'andere foto',
            'dichterbij',
            'van dichterbij',
            'vanuit een andere',
            'maak een',
            'vraag een',
            'mist ',
            'ontbreekt nog',
            'nog niet',
            'kan niet worden vastgesteld',
            'onvoldoende zicht',
            'te donker',
            'te vaag',
            'wazig',
        ]);
    }
}
