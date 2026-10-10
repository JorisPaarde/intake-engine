<?php

declare(strict_types=1);

namespace App\Domains\Intake\Actions;

use App\Domains\AI\Actions\InterpretFollowUpText;
use App\Domains\Intake\Models\AircoRoom;
use App\Domains\Intake\Models\ContributionTask;
use App\Domains\Intake\Models\DossierRecord;
use App\Domains\Intake\Models\DossierSubject;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeFollowUpItem;
use App\Domains\Intake\Services\DossierManager;
use App\Domains\Intake\Support\RoomHeightRequirement;
use App\Enums\DossierRecordKind;
use App\Enums\DossierRecordStatus;
use App\Enums\FollowUpItemType;
use Illuminate\Support\Str;

/**
 * Maps a completed follow-up text answer onto the dossier as a reviewable proposal
 * (BL-146 / intake 100). Never blindly applies “hoogste punt” as average height.
 */
final class ApplyFollowUpTextContribution
{
    public const RECORD_KEY_HEIGHT = 'customer_height_response';

    public const META_REQUESTED_FIELD = 'requested_field';

    public const FIELD_HEIGHT = 'height_m';

    public function __construct(
        private readonly DossierManager $dossierManager,
        private readonly RoomHeightRequirement $heightRequirement,
    ) {}

    public function handle(Intake $intake, IntakeFollowUpItem $item, ContributionTask $task): ?DossierRecord
    {
        if ($item->type !== FollowUpItemType::Text
            || $item->answered_at === null
            || ! is_string($item->response_text)
            || trim($item->response_text) === '') {
            return null;
        }

        $requestedField = $this->resolveRequestedField($task, $item);
        if ($requestedField !== self::FIELD_HEIGHT) {
            return null;
        }

        $room = $this->resolveRoom($intake, $task);
        if ($room === null) {
            return null;
        }

        $subject = $room->subject
            ?? DossierSubject::query()
                ->whereKey($room->dossier_subject_id)
                ->where('intake_id', $intake->id)
                ->first();

        if (! $subject instanceof DossierSubject) {
            return null;
        }

        $hints = resolve(InterpretFollowUpText::class)->extractHeightHints(
            trim($item->response_text),
            $intake,
        );

        return $this->dossierManager->record(
            intake: $intake,
            subject: $subject,
            kind: DossierRecordKind::Observation,
            key: self::RECORD_KEY_HEIGHT,
            value: [
                'text' => trim($item->response_text),
                'prompt' => $item->prompt,
                'requested_field' => self::FIELD_HEIGHT,
                'room_id' => $room->id,
                'room_name' => $room->name,
                'peak_height_m' => $hints['peak_height_m'],
                'knee_wall_height_m' => $hints['knee_wall_height_m'],
                'mentions_sloped_roof' => $hints['mentions_sloped_roof'],
                // Explicit: do not treat peak as usable average height.
                'applied_as_average_height' => false,
                '_field_label' => 'Hoogte (klantaanvulling)',
                '_display_value' => trim($item->response_text),
                '_uncertainty' => $hints['mentions_sloped_roof'] || $hints['peak_height_m'] !== null
                    ? 'Schuin dak: beoordeel welke hoogte voor capaciteit telt. Neem het hoogste punt niet blind over als gemiddelde hoogte.'
                    : 'Beoordeel of deze hoogte als plafondhoogte mag gelden.',
                '_provenance_label' => 'klantantwoord',
                '_source_label' => 'gerichte klanttaak',
                '_status_label' => 'nog te bevestigen',
            ],
            actorType: 'customer',
            actorId: null,
            sourceType: 'intake_follow_up_item',
            sourceId: $item->id,
            method: 'targeted_customer_task',
            confidence: 0.85,
            status: DossierRecordStatus::Proposed,
            evidence: [
                [
                    'type' => 'intake_follow_up_item',
                    'id' => $item->id,
                    'relationship' => 'supports',
                ],
            ],
        );
    }

    /**
     * True when the room still needs height but a pending customer height proposal
     * already covers it — avoid identical re-asks while the installer can review.
     */
    public function hasPendingHeightProposal(Intake $intake, AircoRoom $room): bool
    {
        if (! $this->heightRequirement->missingRequiredHeight($room)) {
            return false;
        }

        return DossierRecord::query()
            ->where('intake_id', $intake->id)
            ->where('dossier_subject_id', $room->dossier_subject_id)
            ->where('key', self::RECORD_KEY_HEIGHT)
            ->where('status', DossierRecordStatus::Proposed)
            ->whereNull('superseded_by_id')
            ->exists();
    }

    private function resolveRequestedField(ContributionTask $task, IntakeFollowUpItem $item): ?string
    {
        $meta = is_array($task->meta) ? $task->meta : [];
        $fromMeta = $meta[self::META_REQUESTED_FIELD] ?? null;
        if (is_string($fromMeta) && $fromMeta !== '') {
            return $fromMeta;
        }

        if ($task->decision_area_key !== 'capacity') {
            return null;
        }

        $prompt = Str::lower($item->prompt.' '.$task->prompt);

        if (Str::contains($prompt, ['hoogte', 'knieschot', 'nok', 'schuin dak'])) {
            return self::FIELD_HEIGHT;
        }

        return null;
    }

    private function resolveRoom(Intake $intake, ContributionTask $task): ?AircoRoom
    {
        $intake->loadMissing('aircoRooms');

        if ($task->dossier_subject_id !== null) {
            $matched = $intake->aircoRooms->first(
                static fn (AircoRoom $room): bool => $room->dossier_subject_id === $task->dossier_subject_id,
            );

            if ($matched instanceof AircoRoom) {
                return $matched;
            }
        }

        // Fallback: first attic still missing height (capacity height asks).
        return $intake->aircoRooms->first(
            fn (AircoRoom $room): bool => $this->heightRequirement->missingRequiredHeight($room),
        );
    }
}
