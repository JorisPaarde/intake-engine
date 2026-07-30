<?php

declare(strict_types=1);

namespace App\Domains\Intake\Actions;

use App\Domains\Intake\Models\DossierRecord;
use App\Domains\Intake\Models\DossierSubject;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeActivityEvent;
use App\Domains\Intake\Services\DossierManager;
use App\Domains\Intake\Services\InstallerSurveyProgress;
use App\Enums\DossierRecordKind;
use App\Enums\DossierRecordStatus;
use App\Models\User;
use Illuminate\Validation\ValidationException;

final class SaveInstallerObservation
{
    public function __construct(
        private readonly DossierManager $dossierManager,
        private readonly InstallerSurveyProgress $surveyProgress,
    ) {}

    public function handle(
        Intake $intake,
        User $installer,
        DossierSubject $subject,
        string $key,
        string $text,
        string $method = 'on_site',
    ): DossierRecord {
        if ($installer->company_id !== $intake->company_id || $subject->intake_id !== $intake->id) {
            throw ValidationException::withMessages([
                'observation' => 'Deze waarneming hoort niet bij dit dossier.',
            ]);
        }

        $record = $this->dossierManager->record(
            intake: $intake,
            subject: $subject,
            kind: DossierRecordKind::Observation,
            key: $key,
            value: ['text' => trim($text)],
            actorType: 'installer',
            actorId: $installer->id,
            sourceType: 'installer',
            sourceId: $installer->id,
            method: $method,
            confidence: 1.0,
            status: DossierRecordStatus::Established,
        );

        IntakeActivityEvent::query()->create([
            'intake_id' => $intake->id,
            'actor_type' => 'user',
            'actor_id' => $installer->id,
            'event' => 'installer_observation_saved',
            'properties' => [
                'record_id' => $record->id,
                'subject_key' => $subject->key,
                'record_key' => $key,
                'method' => $method,
            ],
            'created_at' => now(),
        ]);
        $this->surveyProgress->markStarted($intake);

        return $record;
    }
}
