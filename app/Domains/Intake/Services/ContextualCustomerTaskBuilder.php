<?php

declare(strict_types=1);

namespace App\Domains\Intake\Services;

use App\Domains\Intake\Models\AircoConnection;
use App\Domains\Intake\Models\AircoRoom;
use App\Domains\Intake\Models\DossierDecisionArea;
use App\Domains\Intake\Models\DossierRecord;
use App\Domains\Intake\Models\DossierSubject;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Support\RoomDimensions;
use App\Domains\Intake\Support\RoomHeightRequirement;
use App\Enums\AircoConnectionStatus;
use App\Enums\AircoConnectionType;
use App\Enums\AircoOptionStatus;
use App\Enums\DecisionAreaStatus;
use App\Enums\DossierNextAction;
use App\Enums\FollowUpItemType;
use Illuminate\Support\Str;

/**
 * Builds installer-reviewable customer-task drafts from dossier parts (BL-100).
 *
 * Only safe customer observations (maten, foto’s, routebewijs) — never technical
 * choices such as multi-split/singles, unitpositie, routekeuze or safety checks.
 */
final class ContextualCustomerTaskBuilder
{
    public function __construct(
        private readonly RoomHeightRequirement $heightRequirement,
    ) {}

    /**
     * @return array{
     *     type: string,
     *     prompt: string,
     *     decision_area_key: string,
     *     dossier_subject_id: int|null
     * }|null
     */
    public function forRoom(AircoRoom $room): ?array
    {
        $dimensions = RoomDimensions::from(is_array($room->dimensions) ? $room->dimensions : null);
        $missingUse = $room->use_type === null;
        $needsFloor = ! $dimensions->hasReliableFloorArea()
            || $dimensions->hasFloorAreaConflict()
            || ($dimensions->hasUntrustedAreaM2() && ! $dimensions->hasLengthAndWidth());
        $needsHeight = $this->heightRequirement->missingRequiredHeight($room);

        if (! $missingUse && ! $needsFloor && ! $needsHeight) {
            return null;
        }

        $name = trim((string) $room->name);
        $roomLabel = $name !== '' ? $name : 'deze ruimte';

        if ($dimensions->hasFloorAreaConflict()) {
            $prompt = "Controleer de maten van {$roomLabel}: lengte×breedte en m² komen niet overeen. Noteer lengte en breedte, of één betrouwbaar vloeroppervlak in m².";
        } elseif ($needsFloor) {
            $prompt = "Meet of noteer de lengte en breedte van {$roomLabel}, of het vloeroppervlak in m².";
        } elseif ($needsHeight) {
            $prompt = "Meet of noteer de hoogte van {$roomLabel}.";
        } else {
            $prompt = "Geef aan waarvoor {$roomLabel} vooral gebruikt wordt (bijvoorbeeld slaapkamer of woonkamer).";
        }

        return $this->draft(
            FollowUpItemType::Text,
            $prompt,
            'capacity',
            $room->dossier_subject_id,
        );
    }

    /**
     * @return array{
     *     type: string,
     *     prompt: string,
     *     decision_area_key: string,
     *     dossier_subject_id: int|null
     * }|null
     */
    public function forConnection(AircoConnection $connection): ?array
    {
        if (! in_array($connection->status, [
            AircoConnectionStatus::Unknown,
            AircoConnectionStatus::NeedsEvidence,
        ], true)) {
            return null;
        }

        $label = trim((string) $connection->label);
        $typeLabel = $connection->type->label();
        $from = $connection->fromPlacement?->label;
        $to = $connection->toPlacement?->label;
        $routeHint = ($from !== null && $to !== null)
            ? " ({$from} → {$to})"
            : '';

        $instruction = $connection->routeSession?->next_photo_instruction;
        $prompt = is_string($instruction) && trim($instruction) !== ''
            ? trim($instruction)
            : "Maak een duidelijke foto van de {$typeLabel}"
                .($label !== '' ? " “{$label}”" : '')
                ."{$routeHint}. Laat zien waar de leiding of kabel zichtbaar loopt.";

        $decisionArea = match ($connection->type) {
            AircoConnectionType::Refrigerant => 'refrigerant',
            AircoConnectionType::Condensate => 'condensate',
            AircoConnectionType::Power => 'power',
        };

        return $this->draft(
            FollowUpItemType::Photo,
            $prompt,
            $decisionArea,
            $connection->dossier_subject_id,
        );
    }

    /**
     * @return array{
     *     type: string,
     *     prompt: string,
     *     decision_area_key: string,
     *     dossier_subject_id: int|null
     * }|null
     */
    public function forPhotoSuggestion(DossierSubject $subject, DossierRecord $suggestion): ?array
    {
        $text = is_string($suggestion->value['text'] ?? null)
            ? trim((string) $suggestion->value['text'])
            : '';

        if ($text === '') {
            return null;
        }

        $decisionArea = match ($subject->type) {
            'airco_room' => 'capacity',
            'airco_placement' => 'placement',
            'airco_connection' => match ($subject->meta['connection_type'] ?? null) {
                'refrigerant' => 'refrigerant',
                'condensate' => 'condensate',
                'power' => 'power',
                default => 'placement',
            },
            default => 'placement',
        };

        return $this->draft(
            FollowUpItemType::Photo,
            'Maak een nieuwe, duidelijke foto van '.$subject->label.'. '.$text,
            $decisionArea,
            $subject->id,
        );
    }

    /**
     * @return array{
     *     type: string,
     *     prompt: string,
     *     decision_area_key: string,
     *     dossier_subject_id: int|null
     * }|null
     */
    public function forDecisionArea(Intake $intake, DossierDecisionArea $area): ?array
    {
        if (! in_array($area->status, [
            DecisionAreaStatus::Blocked,
            DecisionAreaStatus::Review,
        ], true)) {
            return null;
        }

        return match ($area->key) {
            'capacity' => $this->capacityAreaAsk($intake),
            'placement' => $this->placementAreaAsk($intake, $area),
            'refrigerant', 'condensate', 'power' => $this->connectionAreaAsk($intake, $area),
            default => null,
        };
    }

    /**
     * @return array{
     *     type: string,
     *     prompt: string,
     *     decision_area_key: string,
     *     dossier_subject_id: int|null
     * }|null
     */
    private function capacityAreaAsk(Intake $intake): ?array
    {
        $incomplete = $intake->aircoRooms->first(
            function (AircoRoom $room): bool {
                $dimensions = RoomDimensions::from(is_array($room->dimensions) ? $room->dimensions : null);

                if ($room->use_type === null) {
                    return true;
                }

                if ($dimensions->hasFloorAreaConflict() || $dimensions->hasUntrustedAreaM2()) {
                    return true;
                }

                if (! $dimensions->hasReliableFloorArea()) {
                    return true;
                }

                return $this->heightRequirement->missingRequiredHeight($room);
            },
        );

        return $incomplete instanceof AircoRoom
            ? $this->forRoom($incomplete)
            : null;
    }

    /**
     * @return array{
     *     type: string,
     *     prompt: string,
     *     decision_area_key: string,
     *     dossier_subject_id: int|null
     * }|null
     */
    private function placementAreaAsk(Intake $intake, DossierDecisionArea $area): ?array
    {
        if ($area->next_action !== DossierNextAction::RequestContribution) {
            return null;
        }

        $blocker = trim((string) ($area->blocker ?? ''));

        // Customer-safe: exterior/around-house photo evidence only.
        // Multi-split/singles and unit configuration stay installer-only (BL-100/103).
        if ($blocker === '' || ! Str::contains(Str::lower($blocker), ['foto', 'gevel', 'tuin', 'montageplek'])) {
            return null;
        }

        if (Str::contains(Str::lower($blocker), ['multi-split', 'singles', 'binnenunit en buitenunit'])) {
            return null;
        }

        return $this->draft(
            FollowUpItemType::Photo,
            $blocker,
            'placement',
            null,
        );
    }

    /**
     * @return array{
     *     type: string,
     *     prompt: string,
     *     decision_area_key: string,
     *     dossier_subject_id: int|null
     * }|null
     */
    private function connectionAreaAsk(Intake $intake, DossierDecisionArea $area): ?array
    {
        if ($area->next_action !== DossierNextAction::RequestContribution) {
            return null;
        }

        $blocker = trim((string) ($area->blocker ?? ''));
        $type = match ($area->key) {
            'refrigerant' => AircoConnectionType::Refrigerant,
            'condensate' => AircoConnectionType::Condensate,
            'power' => AircoConnectionType::Power,
            default => null,
        };

        if ($type === null) {
            return null;
        }

        if (Str::contains(Str::lower($blocker), 'meterkast')) {
            return $this->draft(
                FollowUpItemType::Photo,
                $blocker !== ''
                    ? $blocker
                    : 'Maak een duidelijke foto van de meterkast. Daaruit volgt 1- of 3-fase.',
                'power',
                null,
            );
        }

        $option = $intake->aircoInstallationOptions->first(
            static fn ($option): bool => $option->status === AircoOptionStatus::Selected,
        ) ?? $intake->aircoInstallationOptions->first();

        if ($option === null) {
            // No configuration yet — installer must choose first.
            return null;
        }

        $connection = $option->connections
            ->filter(static fn (AircoConnection $connection): bool => $connection->type === $type)
            ->first(
                static fn (AircoConnection $connection): bool => in_array(
                    $connection->status,
                    [AircoConnectionStatus::Unknown, AircoConnectionStatus::NeedsEvidence],
                    true,
                ),
            );

        if ($connection instanceof AircoConnection) {
            return $this->forConnection($connection);
        }

        // Missing connection objects or uncovered indoor units: installer wiring, not customer choice.
        if (Str::contains(Str::lower($blocker), ['nog geen keuze', 'aan te koppelen', 'niet elke binnenunit'])) {
            return null;
        }

        if ($blocker !== '' && Str::contains(Str::lower($blocker), ['bewijs', 'foto', 'meterkast'])) {
            return $this->draft(
                FollowUpItemType::Photo,
                $blocker,
                $area->key,
                null,
            );
        }

        return null;
    }

    /**
     * @return array{
     *     type: string,
     *     prompt: string,
     *     decision_area_key: string,
     *     dossier_subject_id: int|null
     * }
     */
    private function draft(
        FollowUpItemType $type,
        string $prompt,
        string $decisionAreaKey,
        mixed $dossierSubjectId,
    ): array {
        $subjectId = is_numeric($dossierSubjectId) ? (int) $dossierSubjectId : null;

        return [
            'type' => $type->value,
            'prompt' => Str::limit(trim($prompt), 500, ''),
            'decision_area_key' => $decisionAreaKey,
            'dossier_subject_id' => $subjectId,
        ];
    }
}
