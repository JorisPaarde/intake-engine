<?php

declare(strict_types=1);

namespace App\Domains\Intake\Actions;

use App\Domains\AI\Models\AiRun;
use App\Domains\Intake\Models\DossierEvidenceLink;
use App\Domains\Intake\Models\DossierRecord;
use App\Domains\Intake\Models\DossierSubject;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeActivityEvent;
use App\Domains\Intake\Models\IntakeUpload;
use App\Domains\Intake\Services\DossierManager;
use App\Domains\Intake\Services\InstallerSurveyProgress;
use App\Enums\AiRunStatus;
use App\Enums\DossierRecordKind;
use App\Enums\DossierRecordStatus;
use App\Models\User;
use Illuminate\Validation\ValidationException;

final class ConfirmInstallerObservation
{
    public function __construct(
        private readonly DossierManager $dossierManager,
        private readonly InstallerSurveyProgress $surveyProgress,
    ) {}

    public function handle(
        Intake $intake,
        User $installer,
        DossierRecord $proposal,
        ?string $adjustedText = null,
    ): DossierRecord {
        $subject = $proposal->subject;
        $impact = $proposal->value['impact'] ?? null;
        $photoEvidenceIds = $proposal->evidenceLinks()
            ->where('intake_id', $intake->id)
            ->where('company_id', $intake->company_id)
            ->where('dossier_subject_id', $proposal->dossier_subject_id)
            ->where('evidence_type', 'intake_upload')
            ->pluck('evidence_id');
        $hasPhotoEvidence = IntakeUpload::query()
            ->where('intake_id', $intake->id)
            ->whereIn('id', $photoEvidenceIds)
            ->exists();
        $hasRunEvidence = $proposal->source_id !== null
            && AiRun::query()
                ->whereKey($proposal->source_id)
                ->where('intake_id', $intake->id)
                ->where('status', AiRunStatus::Succeeded)
                ->where('prompt_version', 'like', 'installer-photo-observation-%')
                ->exists()
            && $proposal->evidenceLinks()
                ->where('intake_id', $intake->id)
                ->where('company_id', $intake->company_id)
                ->where('dossier_subject_id', $proposal->dossier_subject_id)
                ->where('evidence_type', 'ai_run')
                ->where('evidence_id', $proposal->source_id)
                ->exists();

        if ($installer->company_id !== $intake->company_id
            || $proposal->intake_id !== $intake->id
            || $proposal->company_id !== $intake->company_id
            || ! $subject instanceof DossierSubject
            || ! in_array($subject->type, ['airco_room', 'airco_placement'], true)
            || $proposal->kind !== DossierRecordKind::Observation
            || $proposal->status !== DossierRecordStatus::Proposed
            || $proposal->source_type !== 'ai'
            || $proposal->method !== 'photo_inference'
            || ! str_starts_with($proposal->key, 'photo_observation.')
            || ! is_string($impact)
            || ! in_array($impact, ['feasibility', 'materials', 'cost', 'installation'], true)
            || ! $hasPhotoEvidence
            || ! $hasRunEvidence) {
            throw ValidationException::withMessages([
                'observation' => 'Deze fotoconstatering kan niet worden bevestigd.',
            ]);
        }

        $originalText = $proposal->value['text'] ?? null;
        $text = $adjustedText === null ? $originalText : trim($adjustedText);

        if (! is_string($text) || trim($text) === '') {
            throw ValidationException::withMessages([
                'text' => 'Beschrijf wat technisch is vastgesteld.',
            ]);
        }

        $evidence = $proposal->evidenceLinks()
            ->get()
            ->map(static fn (DossierEvidenceLink $link): array => [
                'type' => $link->evidence_type,
                'id' => $link->evidence_id,
                'relationship' => $link->relationship,
            ])
            ->values()
            ->all();
        $adjusted = $adjustedText !== null;
        $record = $this->dossierManager->record(
            intake: $intake,
            subject: $subject,
            kind: DossierRecordKind::Observation,
            key: $proposal->key,
            value: [
                'text' => trim($text),
                'impact' => $impact,
            ],
            actorType: 'installer',
            actorId: $installer->id,
            sourceType: 'installer',
            sourceId: $installer->id,
            method: $adjusted ? 'installer_adjusted' : 'installer_confirmed',
            confidence: 1.0,
            status: DossierRecordStatus::Established,
            evidence: $evidence,
        );

        IntakeActivityEvent::query()->create([
            'intake_id' => $intake->id,
            'actor_type' => 'user',
            'actor_id' => $installer->id,
            'event' => 'installer_photo_observation_confirmed',
            'properties' => [
                'proposal_record_id' => $proposal->id,
                'record_id' => $record->id,
                'subject_key' => $subject->key,
                'adjusted' => $adjusted,
            ],
            'created_at' => now(),
        ]);
        $this->surveyProgress->markStarted($intake);

        return $record;
    }
}
