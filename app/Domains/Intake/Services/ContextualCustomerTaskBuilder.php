<?php

declare(strict_types=1);

namespace App\Domains\Intake\Services;

use App\Domains\AI\Support\PhotoSubject;
use App\Domains\Intake\Actions\ApplyFollowUpTextContribution;
use App\Domains\Intake\Models\AircoConnection;
use App\Domains\Intake\Models\AircoRoom;
use App\Domains\Intake\Models\ContributionTask;
use App\Domains\Intake\Models\DossierDecisionArea;
use App\Domains\Intake\Models\DossierRecord;
use App\Domains\Intake\Models\DossierSubject;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Support\CustomerFacingTaskText;
use App\Domains\Intake\Support\PhotoObservationRelevance;
use App\Domains\Intake\Support\RoomDimensions;
use App\Domains\Intake\Support\RoomHeightRequirement;
use App\Enums\AircoConnectionStatus;
use App\Enums\AircoConnectionType;
use App\Enums\AircoOptionStatus;
use App\Enums\ContributionTaskStatus;
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
        private readonly PhotoObservationRelevance $photoObservationRelevance,
        private readonly ApplyFollowUpTextContribution $followUpTextContribution,
    ) {}

    /**
     * @return array{
     *     type: string,
     *     prompt: string,
     *     decision_area_key: string,
     *     dossier_subject_id: int|null,
     *     meta?: array<string, mixed>
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
            // Attic / sloped roof: ask for the measurements that matter, not a single average.
            $prompt = $room->use_type === 'attic'
                ? "Meet of noteer de hoogte van {$roomLabel}: hoogste punt onder de nok én de hoogte bij de knieschotten (schuin dak). Schrijf beide maten op."
                : "Meet of noteer de hoogte van {$roomLabel}.";
        } else {
            $prompt = "Geef aan waarvoor {$roomLabel} vooral gebruikt wordt (bijvoorbeeld slaapkamer of woonkamer).";
        }

        return $this->draft(
            FollowUpItemType::Text,
            $prompt,
            'capacity',
            $room->dossier_subject_id,
            $needsHeight && ! $needsFloor && ! $missingUse
                ? [ApplyFollowUpTextContribution::META_REQUESTED_FIELD => ApplyFollowUpTextContribution::FIELD_HEIGHT]
                : [],
        );
    }

    /**
     * Like forRoom(), but suppresses identical height re-asks when a proposal awaits review.
     *
     * @return array{
     *     type: string,
     *     prompt: string,
     *     decision_area_key: string,
     *     dossier_subject_id: int|null,
     *     meta?: array<string, mixed>
     * }|null
     */
    public function forRoomWithIntake(Intake $intake, AircoRoom $room): ?array
    {
        $dimensions = RoomDimensions::from(is_array($room->dimensions) ? $room->dimensions : null);
        $needsFloor = ! $dimensions->hasReliableFloorArea()
            || $dimensions->hasFloorAreaConflict()
            || ($dimensions->hasUntrustedAreaM2() && ! $dimensions->hasLengthAndWidth());
        $needsHeight = $this->heightRequirement->missingRequiredHeight($room);

        if ($needsHeight
            && ! $needsFloor
            && $room->use_type !== null
            && $this->followUpTextContribution->hasPendingHeightProposal($intake, $room)) {
            return null;
        }

        return $this->forRoom($room);
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

        $instruction = $connection->routeSession?->next_photo_instruction;
        $prompt = is_string($instruction) && trim($instruction) !== ''
            ? trim($instruction)
            : match ($connection->type) {
                AircoConnectionType::Power => 'Maak een foto van de plek waar de stroomkabel naar de buitenunit kan lopen, bijvoorbeeld langs de gevel of vanaf de meterkast.',
                AircoConnectionType::Condensate => 'Maak een foto van de plek waar de condensafvoer kan lozen, zoals een regenpijp, putje of goot.',
                AircoConnectionType::Refrigerant => 'Maak een foto van de route waar de koelleidingen kunnen lopen, bijvoorbeeld langs de gevel of door een muur.',
            };

        $decisionArea = match ($connection->type) {
            AircoConnectionType::Refrigerant => 'refrigerant',
            AircoConnectionType::Condensate => 'condensate',
            AircoConnectionType::Power => 'power',
        };

        // Power connection asks for cable/route evidence — not the meterkast by default.
        // Meterkast asks keep Fusebox via decision_area fallback or explicit fusebox meta.
        $meta = $connection->type === AircoConnectionType::Power
            ? (PhotoSubject::photoTaskMetaForSubtype('power_route') ?? [])
            : [];

        return $this->draft(
            FollowUpItemType::Photo,
            $prompt,
            $decisionArea,
            $connection->dossier_subject_id,
            $meta,
        );
    }

    /**
     * Per-route "Vraag de klant" on a connection card.
     *
     * Condensate evidence is asked once for all indoor units via the decision-area
     * overview (`forDecisionArea` / `connectionAreaAsk`), never per fallback route.
     *
     * @return array{
     *     type: string,
     *     prompt: string,
     *     decision_area_key: string,
     *     dossier_subject_id: int|null
     * }|null
     */
    public function forConnectionCard(AircoConnection $connection): ?array
    {
        // Condensate: one combined ask on the decision-area overview only.
        if ($connection->type === AircoConnectionType::Condensate) {
            return null;
        }

        return $this->forConnection($connection);
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

        if ($text === '' || CustomerFacingTaskText::isInstallerInternal($text)) {
            return null;
        }

        // Only when the note points to concrete missing evidence that changes a decision.
        if (! $this->photoObservationRelevance->warrantsPhotoTask($suggestion)) {
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

        // Avoid doubling “Maak een nieuwe…” when the suggestion already is an action prompt.
        $prompt = Str::startsWith(Str::lower($text), 'maak een')
            ? $text
            : 'Maak een nieuwe, duidelijke foto van '.$subject->label.'. '.$text;

        return $this->draft(
            FollowUpItemType::Photo,
            $prompt,
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
     * AI-uitzondering → bewerkbaar concept (prepare), nooit de interne exceptionzin (N33).
     *
     * Koppel op decision_area_key: eerst AI-customer_task uit dezelfde synthese,
     * anders de vaste klantinstructie via forDecisionArea / verbindingstype.
     *
     * @param  array<string, mixed>  $exception
     * @return array{
     *     type: string,
     *     prompt: string,
     *     decision_area_key: string,
     *     dossier_subject_id: int|null,
     *     meta?: array<string, mixed>
     * }|null
     */
    public function forSynthesisException(Intake $intake, array $exception): ?array
    {
        $areaKey = is_string($exception['decision_area_key'] ?? null)
            ? $exception['decision_area_key']
            : null;

        if ($areaKey === null || ! DecisionReadinessService::hasArea($areaKey)) {
            return null;
        }

        $intake->loadMissing([
            'contributionTasks',
            'decisionAreas',
            'aircoRooms',
            'aircoPlacements',
            'aircoInstallationOptions.connections.routeSession',
        ]);

        $fromAiTask = $this->askFromAiCustomerTask($intake, $areaKey);
        if ($fromAiTask !== null) {
            return $fromAiTask;
        }

        $area = $intake->decisionAreas->firstWhere('key', $areaKey);
        if ($area instanceof DossierDecisionArea) {
            $fromArea = $this->forDecisionArea($intake, $area);
            if ($fromArea !== null) {
                return $fromArea;
            }
        }

        return $this->fallbackAskForArea($intake, $areaKey);
    }

    /**
     * @return array{
     *     type: string,
     *     prompt: string,
     *     decision_area_key: string,
     *     dossier_subject_id: int|null,
     *     meta?: array<string, mixed>
     * }|null
     */
    private function askFromAiCustomerTask(Intake $intake, string $areaKey): ?array
    {
        /** @var ContributionTask|null $task */
        $task = $intake->contributionTasks
            ->filter(static function (ContributionTask $candidate) use ($areaKey): bool {
                return $candidate->status === ContributionTaskStatus::Proposed
                    && $candidate->decision_area_key === $areaKey
                    && ($candidate->meta['source_type'] ?? null) === 'ai';
            })
            ->sortByDesc('id')
            ->first();

        if (! $task instanceof ContributionTask) {
            return null;
        }

        $prompt = trim((string) $task->prompt);
        if ($prompt === '' || CustomerFacingTaskText::isInstallerInternal($prompt)) {
            return null;
        }

        $meta = [];
        $subtype = is_array($task->meta) ? ($task->meta['photo_task_subtype'] ?? null) : null;
        if (is_string($subtype) && $subtype !== '') {
            $photoMeta = PhotoSubject::photoTaskMetaForSubtype($subtype);
            if (is_array($photoMeta)) {
                $meta = $photoMeta;
            }
        }

        return $this->draft(
            $task->type,
            $prompt,
            $areaKey,
            $task->dossier_subject_id,
            $meta,
        );
    }

    /**
     * @return array{
     *     type: string,
     *     prompt: string,
     *     decision_area_key: string,
     *     dossier_subject_id: int|null,
     *     meta?: array<string, mixed>
     * }|null
     */
    private function fallbackAskForArea(Intake $intake, string $areaKey): ?array
    {
        return match ($areaKey) {
            'capacity' => $this->capacityAreaAsk($intake),
            'placement' => null,
            'refrigerant', 'condensate', 'power' => $this->fallbackConnectionAsk($intake, $areaKey),
            default => null,
        };
    }

    /**
     * @return array{
     *     type: string,
     *     prompt: string,
     *     decision_area_key: string,
     *     dossier_subject_id: int|null,
     *     meta?: array<string, mixed>
     * }|null
     */
    private function fallbackConnectionAsk(Intake $intake, string $areaKey): ?array
    {
        $type = match ($areaKey) {
            'refrigerant' => AircoConnectionType::Refrigerant,
            'condensate' => AircoConnectionType::Condensate,
            'power' => AircoConnectionType::Power,
            default => null,
        };

        if ($type === null) {
            return null;
        }

        $option = $intake->aircoInstallationOptions->first(
            static fn ($candidate): bool => $candidate->status === AircoOptionStatus::Selected,
        ) ?? $intake->aircoInstallationOptions->first();

        $connection = $option?->connections
            ->first(static fn (AircoConnection $candidate): bool => $candidate->type === $type);

        $prompt = match ($type) {
            AircoConnectionType::Power => 'Maak een foto van de plek waar de stroomkabel naar de buitenunit kan lopen, bijvoorbeeld langs de gevel of vanaf de meterkast.',
            AircoConnectionType::Condensate => 'Maak een foto van de plek waar de condensafvoer kan lozen, zoals een regenpijp, putje of goot.',
            AircoConnectionType::Refrigerant => 'Maak een foto van de route waar de koelleidingen kunnen lopen, bijvoorbeeld langs de gevel of door een muur.',
        };

        $meta = $type === AircoConnectionType::Power
            ? (PhotoSubject::photoTaskMetaForSubtype('power_route') ?? [])
            : [];

        return $this->draft(
            FollowUpItemType::Photo,
            $prompt,
            $areaKey,
            $connection?->dossier_subject_id,
            $meta,
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
    private function capacityAreaAsk(Intake $intake): ?array
    {
        $incomplete = $intake->aircoRooms->first(
            function (AircoRoom $room) use ($intake): bool {
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

                if (! $this->heightRequirement->missingRequiredHeight($room)) {
                    return false;
                }

                // Pending height proposal → no identical re-ask via capacityAreaAsk.
                return ! $this->followUpTextContribution->hasPendingHeightProposal($intake, $room);
            },
        );

        return $incomplete instanceof AircoRoom
            ? $this->forRoomWithIntake($intake, $incomplete)
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

        // Structured photo subjects — AssessFollowUpPhotoSubject must not skip this
        // just because decision_area_key=placement (intake 99 / empty-room-as-facade).
        // Link evidence to the survey root so received facade photos attach to the dossier
        // (same pattern as room/connection asks with an explicit subject).
        $rootSubjectId = null;
        if ($intake->relationLoaded('dossierSubjects')) {
            $rootSubjectId = $intake->dossierSubjects
                ->first(static fn (DossierSubject $subject): bool => $subject->key === 'survey')
                ?->id;
        } elseif ($intake->exists) {
            $intake->loadMissing('dossierSubjects');
            $rootSubjectId = $intake->dossierSubjects
                ->first(static fn (DossierSubject $subject): bool => $subject->key === 'survey')
                ?->id;
        }

        return $this->draft(
            FollowUpItemType::Photo,
            CustomerFacingTaskText::ensureCustomerFacing($blocker),
            'placement',
            $rootSubjectId,
            PhotoSubject::photoTaskMetaForSubtype('around_house') ?? [],
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

        if (Str::contains(Str::lower($blocker), 'meterkast')
            || PhotoSubject::isInstallerMismatchReason($blocker)) {
            // Installer diagnosis stays on the decision area; customer gets an action prompt.
            $prompt = PhotoSubject::isInstallerMismatchReason($blocker)
                ? PhotoSubject::Fusebox->customerRetakePrompt()
                : CustomerFacingTaskText::fuseboxPhotoPrompt();

            return $this->draft(
                FollowUpItemType::Photo,
                $prompt,
                'power',
                null,
                PhotoSubject::photoTaskMetaForSubtype('fusebox') ?? [],
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
            // Installer mismatch blockers already returned above; remaining text must stay customer-safe.
            return $this->draft(
                FollowUpItemType::Photo,
                CustomerFacingTaskText::ensureCustomerFacing($blocker),
                $area->key,
                null,
            );
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array{
     *     type: string,
     *     prompt: string,
     *     decision_area_key: string,
     *     dossier_subject_id: int|null,
     *     meta?: array<string, mixed>
     * }
     */
    private function draft(
        FollowUpItemType $type,
        string $prompt,
        string $decisionAreaKey,
        mixed $dossierSubjectId,
        array $meta = [],
    ): array {
        $subjectId = is_numeric($dossierSubjectId) ? (int) $dossierSubjectId : null;

        $draft = [
            'type' => $type->value,
            'prompt' => Str::limit(CustomerFacingTaskText::ensureCustomerFacing($prompt), 500, ''),
            'decision_area_key' => $decisionAreaKey,
            'dossier_subject_id' => $subjectId,
        ];

        if ($meta !== []) {
            $draft['meta'] = $meta;
        }

        return $draft;
    }
}
