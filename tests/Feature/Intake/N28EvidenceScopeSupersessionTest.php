<?php

declare(strict_types=1);

use App\Domains\AI\Support\PhotoContentAssessment;
use App\Domains\AI\Support\PhotoSubject;
use App\Domains\Intake\Actions\CreateCustomerContributionRequest;
use App\Domains\Intake\Actions\CreateIntake;
use App\Domains\Intake\Actions\DeleteIntakeUpload;
use App\Domains\Intake\Actions\SaveIntakeAnswer;
use App\Domains\Intake\Actions\StoreIntakeUpload;
use App\Domains\Intake\Models\DossierRecord;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeExternalFact;
use App\Domains\Intake\Models\IntakeUpload;
use App\Domains\Intake\Services\DossierManager;
use App\Domains\Intake\Support\InstallerEvidencePresenter;
use App\Domains\Intake\Support\PrefillSources;
use App\Domains\Intake\Support\UploadSupersessionResolver;
use App\Enums\ContributionMode;
use App\Enums\DossierRecordStatus;
use App\Enums\FollowUpItemType;
use App\Enums\FollowUpRoundStatus;
use App\Enums\IntakeStatus;
use App\Enums\PhotoAssessmentStatus;
use App\Enums\PhotoUsabilityVerdict;
use App\Models\User;
use Database\Seeders\IntakeTemplateSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->seed(IntakeTemplateSeeder::class);
    Storage::fake((string) config('filesystems.media', 'local'));
    config(['ai.provider' => 'fake', 'ai.dossier.enabled' => false]);
});

function n28Intake(User $user): Intake
{
    $intake = app(CreateIntake::class)->handle($user, [
        'template_key' => 'airco',
        'workflow_mode' => ContributionMode::Installer,
        'customer_name' => 'Bewijs Scope',
        'customer_email' => 'bewijs-scope@example.com',
        'address_line' => 'Scopestraat 1',
        'address_postal_code' => '1000AA',
        'address_house_number' => 1,
        'address_city' => 'Amsterdam',
    ]);
    $intake->update(['status' => IntakeStatus::InProgress]);

    return $intake->fresh() ?? $intake;
}

function n28Opaque(string $type, int $id): string
{
    return $type.'_'.substr(hash_hmac('sha256', (string) $id, (string) config('app.key')), 0, 16);
}

function n28MarkOk(IntakeUpload $upload, PhotoSubject $subject): void
{
    $upload->update([
        'assessment_status' => PhotoAssessmentStatus::Assessed,
        'content_assessment' => PhotoContentAssessment::ok($subject, $subject)->toArray(),
        'usability_verdict' => PhotoUsabilityVerdict::Ok,
    ]);
}

function n28FollowUpUpload(Intake $intake, int $itemId, string $filename): IntakeUpload
{
    $path = 'private/intakes/'.$intake->id.'/'.$filename;
    Storage::disk((string) config('filesystems.media', 'local'))->put($path, 'fake-image');

    return IntakeUpload::query()->create([
        'intake_id' => $intake->id,
        'question_key' => 'follow_up_upload',
        'section_instance_key' => null,
        'intake_follow_up_item_id' => $itemId,
        'disk' => (string) config('filesystems.media', 'local'),
        'path' => $path,
        'original_filename' => $filename,
        'mime_type' => 'image/jpeg',
        'size_bytes' => 1200,
        'sort_order' => 0,
    ]);
}

function n28CompleteRound($round, IntakeUpload $upload): void
{
    $upload->followUpItem?->update(['answered_at' => now()]);
    $round->update([
        'status' => FollowUpRoundStatus::Completed,
        'completed_at' => now(),
    ]);
}

test('N28: power_route pipe_route photo does not supersede wizard fusebox', function () {
    $user = User::factory()->create();
    $intake = n28Intake($user);

    $fusebox = app(StoreIntakeUpload::class)->handle(
        $intake,
        'fusebox_photo',
        null,
        UploadedFile::fake()->image('meterkast.jpg', 640, 480),
    );
    n28MarkOk($fusebox, PhotoSubject::Fusebox);

    $fact = IntakeExternalFact::query()->create([
        'intake_id' => $intake->id,
        'fact_key' => 'fusebox_photo_assessment',
        'label' => 'Automatische beoordeling meterkastfoto',
        'value' => [
            'empty_module_space' => 'visible',
            'phase' => 'three_phase',
            'upload_ids' => [$fusebox->id],
        ],
        'source' => 'ai_photo',
        'confidence' => 'medium',
        'captured_at' => now(),
    ]);

    $meta = PhotoSubject::photoTaskMetaForSubtype('power_route');
    expect($meta)->not->toBeNull();

    $round = app(CreateCustomerContributionRequest::class)->handle($intake->fresh(), $user, [[
        'type' => FollowUpItemType::Photo,
        'prompt' => 'Maak een foto van de plek waar de stroomkabel naar de buitenunit kan lopen.',
        'decision_area_key' => 'power',
        'dossier_subject_id' => null,
        'photo_task_subtype' => 'power_route',
        'meta' => $meta,
    ]]);

    $item = $round->items()->firstOrFail();
    $route = n28FollowUpUpload($intake, (int) $item->id, 'stroomroute.jpg');
    n28MarkOk($route, PhotoSubject::PipeRoute);
    n28CompleteRound($round, $route);
    $intake->update(['status' => IntakeStatus::InProgress]);

    $supersessions = app(UploadSupersessionResolver::class)->resolve($intake->fresh());

    expect($supersessions[(int) $fusebox->id]['superseded'])->toBeFalse()
        ->and($supersessions[(int) $fusebox->id]['replaced_by_upload_id'])->toBeNull()
        ->and($supersessions[(int) $route->id]['superseded'])->toBeFalse();

    $citations = app(InstallerEvidencePresenter::class)->presentAttentionEvidence($intake->fresh(), [
        [
            'source_type' => 'external_fact',
            'reference' => 'fusebox_photo_assessment@fact:'.n28Opaque('fact', (int) $fact->id),
        ],
    ]);

    expect($citations)->toHaveCount(1)
        ->and($citations[0]['upload_id'])->toBe((int) $fusebox->id)
        ->and(mb_strtolower($citations[0]['label']))->toContain('meterkast')
        ->and($citations[0]['superseded'])->toBeFalse()
        ->and($citations[0]['label'])->not->toContain('ronde 1');
});

test('N28: power fusebox subtype follow-up still replaces wizard fusebox', function (string $subtypeOrNone) {
    $user = User::factory()->create();
    $intake = n28Intake($user);

    $old = app(StoreIntakeUpload::class)->handle(
        $intake,
        'fusebox_photo',
        null,
        UploadedFile::fake()->image('meterkast-oud.jpg', 640, 480),
    );
    n28MarkOk($old, PhotoSubject::Fusebox);

    $itemPayload = [
        'type' => FollowUpItemType::Photo,
        'prompt' => 'Maak een leesbare foto van de volledige meterkast.',
        'decision_area_key' => 'power',
        'dossier_subject_id' => null,
    ];
    if ($subtypeOrNone === 'fusebox') {
        $itemPayload['photo_task_subtype'] = 'fusebox';
        $itemPayload['meta'] = PhotoSubject::photoTaskMetaForSubtype('fusebox');
    }

    $round = app(CreateCustomerContributionRequest::class)->handle($intake->fresh(), $user, [$itemPayload]);
    $item = $round->items()->firstOrFail();
    $new = n28FollowUpUpload($intake, (int) $item->id, 'meterkast-nieuw.jpg');
    n28MarkOk($new, PhotoSubject::Fusebox);
    n28CompleteRound($round, $new);
    $intake->update(['status' => IntakeStatus::InProgress]);

    $supersessions = app(UploadSupersessionResolver::class)->resolve($intake->fresh());

    expect($supersessions[(int) $old->id]['superseded'])->toBeTrue()
        ->and($supersessions[(int) $old->id]['supersession_label'])->toBe('Vervangen door ronde 1')
        ->and($supersessions[(int) $old->id]['replaced_by_upload_id'])->toBe((int) $new->id);
})->with([
    'no subtype',
    'fusebox',
]);

test('N28: wizard fusebox with wrong_subject detection still superseded by fusebox follow-up', function () {
    $user = User::factory()->create();
    $intake = n28Intake($user);

    $wizard = app(StoreIntakeUpload::class)->handle(
        $intake,
        'fusebox_photo',
        null,
        UploadedFile::fake()->image('meterkast-wrong.jpg', 640, 480),
    );
    $wizard->update([
        'assessment_status' => PhotoAssessmentStatus::Assessed,
        'content_assessment' => PhotoContentAssessment::wrongSubject(
            PhotoSubject::Fusebox,
            PhotoSubject::PipeRoute,
            'fusebox_photo',
        )->toArray(),
        'usability_verdict' => PhotoUsabilityVerdict::Ok,
    ]);

    $round = app(CreateCustomerContributionRequest::class)->handle($intake->fresh(), $user, [[
        'type' => FollowUpItemType::Photo,
        'prompt' => 'Maak een leesbare foto van de volledige meterkast.',
        'decision_area_key' => 'power',
        'dossier_subject_id' => null,
        'photo_task_subtype' => 'fusebox',
        'meta' => PhotoSubject::photoTaskMetaForSubtype('fusebox'),
    ]]);

    $item = $round->items()->firstOrFail();
    $replacement = n28FollowUpUpload($intake, (int) $item->id, 'meterkast-goed.jpg');
    n28MarkOk($replacement, PhotoSubject::Fusebox);
    n28CompleteRound($round, $replacement);
    $intake->update(['status' => IntakeStatus::InProgress]);

    $supersessions = app(UploadSupersessionResolver::class)->resolve($intake->fresh());

    expect($supersessions[(int) $wizard->id]['superseded'])->toBeTrue()
        ->and($supersessions[(int) $wizard->id]['supersession_label'])->toBe('Vervangen door ronde 1')
        ->and($supersessions[(int) $wizard->id]['replaced_by_upload_id'])->toBe((int) $replacement->id);
});

test('N28: condensate follow-up outdoor photo does not supersede wizard fusebox', function () {
    $user = User::factory()->create();
    $intake = n28Intake($user);

    $fusebox = app(StoreIntakeUpload::class)->handle(
        $intake,
        'fusebox_photo',
        null,
        UploadedFile::fake()->image('meterkast.jpg', 640, 480),
    );
    n28MarkOk($fusebox, PhotoSubject::Fusebox);

    $round = app(CreateCustomerContributionRequest::class)->handle($intake->fresh(), $user, [[
        'type' => FollowUpItemType::Photo,
        'prompt' => 'Maak een foto van de gevel waar condenswater weg kan.',
        'decision_area_key' => 'condensate',
        'dossier_subject_id' => null,
    ]]);

    $item = $round->items()->firstOrFail();
    $outdoor = n28FollowUpUpload($intake, (int) $item->id, 'gevel-condens.jpg');
    n28MarkOk($outdoor, PhotoSubject::OutdoorLocation);
    n28CompleteRound($round, $outdoor);
    $intake->update(['status' => IntakeStatus::InProgress]);

    $supersessions = app(UploadSupersessionResolver::class)->resolve($intake->fresh());

    expect($supersessions[(int) $fusebox->id]['superseded'])->toBeFalse()
        ->and($supersessions[(int) $outdoor->id]['superseded'])->toBeFalse();
});

test('N31: replacing fusebox clarity leaves one active dossier proposal; undo restores', function () {
    $user = User::factory()->create();
    $intake = n28Intake($user);
    $dossier = app(DossierManager::class);
    $save = app(SaveIntakeAnswer::class);

    $blurry = app(StoreIntakeUpload::class)->handle(
        $intake,
        'fusebox_photo',
        null,
        UploadedFile::fake()->image('meterkast-blur.jpg', 640, 480),
    );
    $blurry->update([
        'assessment_status' => PhotoAssessmentStatus::Assessed,
        'content_assessment' => PhotoContentAssessment::needsClearer(
            PhotoSubject::Fusebox,
            'De meterkast is niet scherp genoeg.',
        )->toArray(),
        'usability_verdict' => PhotoUsabilityVerdict::TooDark,
    ]);

    $save->handle($intake, 'fusebox_clarity', null, ['value' => 'needs_clearer_photo'], PrefillSources::AI_PHOTO);
    $dossier->initialize($intake->fresh());

    $firstAnswer = $intake->fresh()->answers()->where('question_key', 'fusebox_clarity')->firstOrFail();
    $firstRecord = DossierRecord::query()
        ->where('intake_id', $intake->id)
        ->where('source_type', 'intake_answer')
        ->where('source_id', $firstAnswer->id)
        ->firstOrFail();

    expect($firstRecord->status)->toBe(DossierRecordStatus::Proposed)
        ->and($firstRecord->superseded_by_id)->toBeNull();

    $snapshot = app(DeleteIntakeUpload::class)->snapshotDerivedState($intake->fresh(), $blurry);

    // Simulate AssessFuseboxPhotos::invalidateDerivedState + new clarity after sharp retake.
    $firstAnswer->delete();
    $intake->unsetRelation('answers');

    $sharp = app(StoreIntakeUpload::class)->handle(
        $intake->fresh(),
        'fusebox_photo',
        null,
        UploadedFile::fake()->image('meterkast-sharp.jpg', 640, 480),
    );
    n28MarkOk($sharp, PhotoSubject::Fusebox);

    $save->handle($intake->fresh(), 'fusebox_clarity', null, ['value' => 'clear'], PrefillSources::AI_PHOTO);
    $dossier->initialize($intake->fresh());

    $activeClarity = DossierRecord::query()
        ->where('intake_id', $intake->id)
        ->where('key', 'answer.fusebox_clarity')
        ->whereIn('status', [
            DossierRecordStatus::Proposed,
            DossierRecordStatus::Established,
            DossierRecordStatus::Conflicted,
        ])
        ->whereNull('superseded_by_id')
        ->get();

    expect($activeClarity)->toHaveCount(1);

    $secondAnswer = $intake->fresh()->answers()->where('question_key', 'fusebox_clarity')->firstOrFail();
    expect((int) $activeClarity->first()->source_id)->toBe((int) $secondAnswer->id)
        ->and($firstRecord->fresh()->status)->toBe(DossierRecordStatus::Superseded)
        ->and($firstRecord->fresh()->superseded_by_id)->toBe((int) $activeClarity->first()->id);

    // Undo path: drop the new clarity, restore the snapshotted needs_clearer answer.
    $secondAnswer->delete();
    $intake->unsetRelation('answers');
    app(DeleteIntakeUpload::class)->restoreDerivedState($intake->fresh(), $snapshot);
    $dossier->initialize($intake->fresh());

    $restoredActive = DossierRecord::query()
        ->where('intake_id', $intake->id)
        ->where('key', 'answer.fusebox_clarity')
        ->whereIn('status', [
            DossierRecordStatus::Proposed,
            DossierRecordStatus::Established,
            DossierRecordStatus::Conflicted,
        ])
        ->whereNull('superseded_by_id')
        ->get();

    expect($restoredActive)->toHaveCount(1);

    $restoredAnswer = $intake->fresh()->answers()->where('question_key', 'fusebox_clarity')->firstOrFail();
    expect($restoredAnswer->value)->toBe(['value' => 'needs_clearer_photo'])
        ->and((int) $restoredActive->first()->source_id)->toBe((int) $restoredAnswer->id);
});
