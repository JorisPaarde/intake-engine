<?php

declare(strict_types=1);

namespace App\Domains\AI\Actions;

use App\Domains\AI\Support\ExistingAircoExtractor;
use App\Domains\Intake\Models\AircoRoom;
use App\Domains\Intake\Models\DossierRecord;
use App\Domains\Intake\Models\DossierSubject;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeAnswer;
use App\Domains\Intake\Models\IntakeAttentionPoint;
use App\Domains\Intake\Services\DossierManager;
use App\Domains\Intake\Support\FactAcceptance;
use App\Domains\Intake\Support\FactProvenance;
use App\Domains\Intake\Support\PrefillSources;
use App\Enums\AttentionPointSource;
use App\Enums\AttentionPointStatus;
use App\Enums\DossierRecordKind;
use App\Enums\DossierRecordStatus;

/**
 * Legt bestaande airco + vervanging vast als dossierfeit en aandachtspunt.
 * Idempotent: zelfde open system-record → geen herschrijven; installer-record → nooit overschrijven.
 */
final class RecordExistingAircoFromRequest
{
    public const RECORD_KEY = 'existing_airco';

    public const ATTENTION_CODE = 'existing_airco_replacement';

    public function __construct(
        private readonly ExistingAircoExtractor $extractor,
        private readonly DossierManager $dossierManager,
    ) {}

    public function handle(Intake $intake, ?string $requestReason = null): void
    {
        $reason = $requestReason;
        if ($reason === null) {
            $answer = $intake->answers
                ->first(static fn (IntakeAnswer $a): bool => $a->question_key === 'request_reason')
                ?? $intake->answers()->where('question_key', 'request_reason')->first();
            $value = is_array($answer?->value) ? $answer->value : [];
            $reason = is_string($value['text'] ?? null) ? $value['text'] : null;
        }

        if (! is_string($reason) || trim($reason) === '') {
            return;
        }

        $extracted = $this->extractor->extract($reason);
        if ($extracted === null) {
            return;
        }

        $label = $extracted['replacement']
            ? 'Bestaande airco vervangen'
            : 'Bestaande airco aanwezig';
        $roomLabel = match ($extracted['room_type']) {
            'living_room' => 'woonkamer',
            'bedroom' => 'slaapkamer',
            'office' => 'werkkamer',
            'attic' => 'zolder',
            default => null,
        };
        $summary = $label.($roomLabel !== null ? ' ('.$roomLabel.')' : '');

        $open = DossierRecord::query()
            ->where('intake_id', $intake->id)
            ->where('key', self::RECORD_KEY)
            ->whereNull('superseded_by_id')
            ->whereIn('status', [
                DossierRecordStatus::Proposed,
                DossierRecordStatus::Established,
                DossierRecordStatus::Conflicted,
            ])
            ->latest('id')
            ->first();

        if ($open instanceof DossierRecord) {
            // Installateur/menselijke vastlegging nooit overschrijven.
            if ($open->actor_type !== 'system') {
                return;
            }

            $existingValue = $open->value;
            if (
                ($existingValue['present'] ?? null) === true
                && ($existingValue['replacement'] ?? null) === $extracted['replacement']
                && ($existingValue['room_type'] ?? null) === $extracted['room_type']
                && ($existingValue['text'] ?? null) === $summary
            ) {
                return;
            }
        }

        $this->dossierManager->initialize($intake);
        $intake->refresh();
        $intake->loadMissing(['dossierSubjects', 'aircoRooms', 'answers']);

        $subject = $this->resolveSubject($intake, $extracted['room_type']);
        if (! $subject instanceof DossierSubject) {
            return;
        }

        $reasonAnswer = $intake->answers
            ->first(static fn (IntakeAnswer $a): bool => $a->question_key === 'request_reason'
                && $a->section_instance_key === null)
            ?? $intake->answers()->where('question_key', 'request_reason')->whereNull('section_instance_key')->first();
        $sourceLabel = FactAcceptance::sourceFrom(
            PrefillSources::AI_TEXT,
            FactProvenance::Stated,
            $reasonAnswer?->prefill_source,
        )->installerLabel();

        $this->dossierManager->record(
            intake: $intake,
            subject: $subject,
            kind: DossierRecordKind::Observation,
            key: self::RECORD_KEY,
            value: [
                'text' => $summary,
                'present' => true,
                'replacement' => $extracted['replacement'],
                'room_type' => $extracted['room_type'],
                '_field_label' => 'Bestaande airco',
                '_display_value' => $summary,
                '_source_label' => $sourceLabel,
                '_provenance_label' => 'gezegd',
                '_evidence' => $extracted['evidence'],
            ],
            actorType: 'system',
            actorId: null,
            sourceType: 'request_text',
            sourceId: null,
            method: 'request_text_extract',
            confidence: 0.9,
            status: DossierRecordStatus::Established,
        );

        $attentionLabel = $extracted['replacement']
            ? 'Bestaande airco demontage/vervanging meenemen in de prijs'
            : 'Bestaande airco in de woning — controleer of die blijft of weg moet';

        $existing = $intake->attentionPoints()
            ->where('code', self::ATTENTION_CODE)
            ->first();

        if ($existing instanceof IntakeAttentionPoint) {
            if ($existing->status === AttentionPointStatus::Proposed
                || $existing->status === null) {
                $existing->update([
                    'label' => $attentionLabel,
                    'evidence' => [[
                        'source_type' => 'answer',
                        'reference' => 'request_reason',
                    ]],
                ]);
            }

            return;
        }

        IntakeAttentionPoint::query()->create([
            'intake_id' => $intake->id,
            'source' => AttentionPointSource::System,
            'code' => self::ATTENTION_CODE,
            'label' => $attentionLabel,
            'status' => AttentionPointStatus::Proposed,
            'ai_confidence' => 'high',
            'evidence' => [[
                'source_type' => 'answer',
                'reference' => 'request_reason',
            ]],
        ]);
    }

    private function resolveSubject(Intake $intake, ?string $roomType): ?DossierSubject
    {
        if (is_string($roomType)) {
            $room = $intake->aircoRooms->first(
                static fn (AircoRoom $room): bool => $room->use_type === $roomType,
            );
            if ($room instanceof AircoRoom) {
                $subject = $intake->dossierSubjects->firstWhere('id', $room->dossier_subject_id);
                if ($subject instanceof DossierSubject) {
                    return $subject;
                }
            }

            $answer = $intake->answers->first(
                static fn (IntakeAnswer $a): bool => $a->question_key === 'room_type'
                    && is_array($a->value)
                    && ($a->value['value'] ?? null) === $roomType,
            );
            if ($answer instanceof IntakeAnswer && is_string($answer->section_instance_key)) {
                $subject = $intake->dossierSubjects->firstWhere(
                    'key',
                    'airco.room.'.$answer->section_instance_key,
                );
                if ($subject instanceof DossierSubject) {
                    return $subject;
                }
            }
        }

        return $intake->dossierSubjects->firstWhere('key', 'survey');
    }
}
