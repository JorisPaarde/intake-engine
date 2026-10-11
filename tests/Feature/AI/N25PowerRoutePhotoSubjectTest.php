<?php

declare(strict_types=1);

use App\Domains\AI\Actions\AssessFollowUpPhotoSubject;
use App\Domains\AI\Clients\FakeAiClient;
use App\Domains\AI\Jobs\AssessUploadedPhotoJob;
use App\Domains\AI\Support\PhotoContentAssessment;
use App\Domains\AI\Support\PhotoSubject;
use App\Domains\Intake\Actions\CreateCustomerContributionRequest;
use App\Domains\Intake\Actions\CreateIntake;
use App\Domains\Intake\Actions\StoreFollowUpUpload;
use App\Domains\Intake\Models\AircoConnection;
use App\Domains\Intake\Models\ContributionTask;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Services\ContextualCustomerTaskBuilder;
use App\Domains\Intake\Services\DossierManager;
use App\Domains\Intake\Support\FollowUpContributionPresenter;
use App\Enums\AircoConnectionStatus;
use App\Enums\AircoConnectionType;
use App\Enums\ContributionMode;
use App\Enums\ContributionTaskStatus;
use App\Enums\FollowUpItemType;
use App\Enums\FollowUpRoundStatus;
use App\Models\User;
use Database\Seeders\IntakeTemplateSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->seed(IntakeTemplateSeeder::class);
    Storage::fake((string) config('filesystems.media', 'local'));
    FakeAiClient::reset();
    config([
        'ai.provider' => 'fake',
        'ai.photo_inference.enabled' => true,
        'ai.dossier.enabled' => false,
    ]);
});

afterEach(function () {
    FakeAiClient::reset();
});

function n25Intake(User $user): Intake
{
    return app(CreateIntake::class)->handle($user, [
        'template_key' => 'airco',
        'workflow_mode' => ContributionMode::Installer,
        'customer_name' => 'N25 Test',
        'customer_email' => 'n25@example.com',
        'address_line' => 'Testlaan 1',
        'address_postal_code' => '1000AA',
        'address_house_number' => 1,
        'address_city' => 'Amsterdam',
    ]);
}

/**
 * @param  array<string, mixed>  $draft
 */
function n25CompletePhotoRound(Intake $intake, User $user, array $draft): Intake
{
    $round = app(CreateCustomerContributionRequest::class)->handle($intake->fresh(), $user, [$draft]);
    $item = $round->items()->firstOrFail();
    $task = ContributionTask::query()->where('intake_follow_up_item_id', $item->id)->firstOrFail();

    $task->update([
        'status' => ContributionTaskStatus::Completed,
        'completed_at' => now(),
    ]);
    $round->update([
        'status' => FollowUpRoundStatus::Completed,
        'completed_at' => now(),
    ]);

    return $intake->fresh();
}

test('N25: power-connection task accepts pipe_route photo without meterkast mismatch', function () {
    $user = User::factory()->create();
    $intake = n25Intake($user);
    app(DossierManager::class)->initialize($intake);

    $connection = (new AircoConnection)->forceFill([
        'id' => 1,
        'label' => 'Stroomtoevoer buitenunit',
        'type' => AircoConnectionType::Power,
        'status' => AircoConnectionStatus::NeedsEvidence,
        'dossier_subject_id' => app(DossierManager::class)->root($intake)->id,
    ]);
    $connection->setRelation('fromPlacement', null);
    $connection->setRelation('toPlacement', null);
    $connection->setRelation('routeSession', null);

    $draft = app(ContextualCustomerTaskBuilder::class)->forConnection($connection);
    expect($draft)->not->toBeNull()
        ->and($draft['meta']['photo_task_subtype'] ?? null)->toBe('power_route');

    $round = app(CreateCustomerContributionRequest::class)->handle($intake->fresh(), $user, [$draft]);
    $item = $round->items()->firstOrFail();
    $task = ContributionTask::query()->where('intake_follow_up_item_id', $item->id)->firstOrFail();

    expect(PhotoSubject::acceptedSubjectsForTask($task))->toContain(PhotoSubject::PipeRoute)
        ->and(PhotoSubject::acceptedSubjectsForTask($task))->toContain(PhotoSubject::OutdoorUnit)
        ->and(PhotoSubject::expectedForTask($task))->toBe(PhotoSubject::PipeRoute);

    FakeAiClient::alwaysReturn([
        'detected_subject' => 'pipe_route',
        'subject_match' => 'yes',
        'evidence' => 'Leidingroute langs de gevel met kabel zichtbaar.',
    ]);

    Queue::fake([AssessUploadedPhotoJob::class]);
    $upload = app(StoreFollowUpUpload::class)->handle(
        $intake->fresh(),
        $item,
        UploadedFile::fake()->image('leidingroute.jpg', 1200, 900),
    );

    $result = app(AssessFollowUpPhotoSubject::class)->handle($intake->fresh(), $item, $upload);

    expect($result['assessment']?->status())->toBe(PhotoContentAssessment::STATUS_OK)
        ->and($result['assessment']?->customerMessage() ?? '')->not->toContain('meterkast')
        ->and($result['message'])->toBeNull();
});

test('N25: meterkast power task still rejects non-fusebox with meterkast message', function () {
    $user = User::factory()->create();
    $intake = n25Intake($user);
    app(DossierManager::class)->initialize($intake);

    $round = app(CreateCustomerContributionRequest::class)->handle($intake->fresh(), $user, [[
        'type' => FollowUpItemType::Photo->value,
        'prompt' => 'Maak een duidelijke foto van de meterkast; maak de groepenkast volledig leesbaar. De installateur beoordeelt de aansluiting.',
        'decision_area_key' => 'power',
        'dossier_subject_id' => null,
        'meta' => [
            'photo_task_subtype' => 'fusebox',
            'expected_photo_subject' => PhotoSubject::Fusebox->value,
            'accepted_photo_subjects' => [PhotoSubject::Fusebox->value],
        ],
    ]]);
    $item = $round->items()->firstOrFail();
    $task = ContributionTask::query()->where('intake_follow_up_item_id', $item->id)->firstOrFail();

    expect(PhotoSubject::expectedForTask($task))->toBe(PhotoSubject::Fusebox);

    FakeAiClient::alwaysReturn([
        'detected_subject' => 'pipe_route',
        'subject_match' => 'no',
        'evidence' => 'Dit lijkt een foto van de leidingroute.',
    ]);

    Queue::fake([AssessUploadedPhotoJob::class]);
    $upload = app(StoreFollowUpUpload::class)->handle(
        $intake->fresh(),
        $item,
        UploadedFile::fake()->image('leidingroute.jpg', 1200, 900),
    );

    $result = app(AssessFollowUpPhotoSubject::class)->handle($intake->fresh(), $item, $upload);
    $message = (string) ($result['assessment']?->customerMessage() ?? $result['message'] ?? '');

    expect($result['assessment']?->status())->toBe(PhotoContentAssessment::STATUS_WRONG_SUBJECT)
        ->and($message)->toContain('meterkast')
        ->and($message)->toContain('leidingroute');
});

test('N25: installer decides copy distinguishes power route vs meterkast', function () {
    $user = User::factory()->create();
    $intake = n25Intake($user);
    app(DossierManager::class)->initialize($intake);

    $routeIntake = n25CompletePhotoRound($intake, $user, [
        'type' => FollowUpItemType::Photo->value,
        'prompt' => 'Maak een foto van de plek waar de stroomkabel naar de buitenunit kan lopen, bijvoorbeeld langs de gevel of vanaf de meterkast.',
        'decision_area_key' => 'power',
        'dossier_subject_id' => null,
        'meta' => [
            'photo_task_subtype' => 'power_route',
            'expected_photo_subject' => PhotoSubject::PipeRoute->value,
            'accepted_photo_subjects' => [
                PhotoSubject::PipeRoute->value,
                PhotoSubject::OutdoorUnit->value,
            ],
        ],
    ]);

    expect(app(FollowUpContributionPresenter::class)->present($routeIntake)['items'][0]['installer_decides'])
        ->toBe('Jij beslist over de stroomroute en of er een controle op locatie nodig is.');

    $fuseboxIntake = n25CompletePhotoRound($routeIntake, $user, [
        'type' => FollowUpItemType::Photo->value,
        'prompt' => 'Maak een nieuwe, duidelijke foto van je meterkast',
        'decision_area_key' => 'power',
        'dossier_subject_id' => null,
        'meta' => [
            'photo_task_subtype' => 'fusebox',
            'expected_photo_subject' => PhotoSubject::Fusebox->value,
            'accepted_photo_subjects' => [PhotoSubject::Fusebox->value],
        ],
    ]);

    expect(app(FollowUpContributionPresenter::class)->present($fuseboxIntake)['items'][0]['installer_decides'])
        ->toBe('Jij beslist of de meterkast leesbaar genoeg is voor 1- of 3-fase en de technische route.');
});
