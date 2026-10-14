<?php

declare(strict_types=1);

use App\Domains\AI\Actions\SummarizeIntake;
use App\Domains\AI\Clients\FakeAiClient;
use App\Domains\AI\Jobs\SuggestAttentionPointsJob;
use App\Domains\AI\Jobs\SummarizeIntakeJob;
use App\Domains\AI\Jobs\SynthesizeSurveyDossierJob;
use App\Domains\AI\Models\AiRun;
use App\Domains\AI\Services\SummaryDossierContext;
use App\Domains\Intake\Actions\CreateIntake;
use App\Domains\Intake\Jobs\GenerateIntakePdfJob;
use App\Domains\Intake\Models\AircoRoom;
use App\Domains\Intake\Models\DossierRecord;
use App\Domains\Intake\Models\DossierSubject;
use App\Domains\Intake\Models\GeneratedReport;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Services\AircoSurveyService;
use App\Domains\Intake\Services\RefreshStoredIntakeReport;
use App\Enums\AircoConfigurationType;
use App\Enums\AircoPlacementType;
use App\Enums\ContributionMode;
use App\Enums\DossierRecordKind;
use App\Enums\DossierRecordStatus;
use App\Enums\IntakeStatus;
use App\Models\User;
use Database\Seeders\IntakeTemplateSeeder;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Testing\Fakes\QueueFake;

beforeEach(function () {
    $this->seed(IntakeTemplateSeeder::class);
    FakeAiClient::reset();
    config(['ai.provider' => 'fake']);
    FakeAiClient::alwaysReturn([
        'summary' => 'Samenvatting voor de installateur over deze aanvraag.',
        'highlights' => ['Controleer de woonkamer.'],
    ]);
});

afterEach(fn () => FakeAiClient::reset());

/** @return array{0: User, 1: Intake, 2: AircoRoom} */
function n69IntakeWithSelectedBedroomsOnly(?User $user = null): array
{
    $user ??= User::factory()->create();
    $intake = app(CreateIntake::class)->handle($user, [
        'template_key' => 'airco',
        'workflow_mode' => ContributionMode::Installer,
        'customer_name' => 'Scopetest',
        'customer_email' => 'scope@example.com',
        'address_line' => 'Testlaan 10',
        'address_postal_code' => '1000AA',
        'address_house_number' => 10,
        'address_city' => 'Amsterdam',
    ]);
    $survey = app(AircoSurveyService::class);

    $living = $survey->createRoom($intake, $user, ['name' => 'Woonkamer', 'use_type' => 'living_room']);
    $a = $survey->createRoom($intake, $user, ['name' => 'Slaapkamer 1', 'use_type' => 'bedroom']);
    $b = $survey->createRoom($intake, $user, ['name' => 'Slaapkamer 2', 'use_type' => 'bedroom']);
    $inA = $survey->createPlacement($intake, $user, ['airco_room_id' => $a->id, 'type' => AircoPlacementType::IndoorUnit, 'label' => 'Unit 1']);
    $inB = $survey->createPlacement($intake, $user, ['airco_room_id' => $b->id, 'type' => AircoPlacementType::IndoorUnit, 'label' => 'Unit 2']);
    $out = $survey->createPlacement($intake, $user, ['type' => AircoPlacementType::OutdoorUnit, 'label' => 'Buiten']);
    $option = $survey->createInstallationOption($intake, $user, [
        'label' => 'Multi-split twee slaapkamers',
        'configuration_type' => AircoConfigurationType::MultiSplit,
        'placement_ids' => [$inA->id, $inB->id, $out->id],
        'refrigerant_links' => [
            ['from_placement_id' => $inA->id, 'to_placement_id' => $out->id],
            ['from_placement_id' => $inB->id, 'to_placement_id' => $out->id],
        ],
    ]);
    $survey->markInstallationOptionFeasible($intake, $user, $option);
    $survey->selectInstallationOption($intake, $user, $option);

    return [$user, $intake->fresh(), $living];
}

function n69CompletedWithReport(Intake $intake): Intake
{
    $intake->update(['status' => IntakeStatus::Completed]);
    GeneratedReport::query()->updateOrCreate(
        ['intake_id' => $intake->id],
        ['html' => '<p>x</p>', 'meta' => [], 'generated_at' => now()],
    );

    return $intake->fresh();
}

test('summary input contains the current dossier state incl. installer scope decision', function () {
    [$user, $intake, $living] = n69IntakeWithSelectedBedroomsOnly();
    app(AircoSurveyService::class)->confirmRoomExistingInstallation($intake, $user, $living);
    $intake = n69CompletedWithReport($intake);

    app(SummarizeIntake::class)->handle($intake);

    $payload = AiRun::query()->where('intake_id', $intake->id)->latest('id')->firstOrFail();
    $dossier = app(SummaryDossierContext::class)->build($intake->fresh());
    $roomRows = collect($dossier['rooms'])->keyBy('name');

    expect($roomRows['Woonkamer']['existing_installation_remains_confirmed_by_installer'])->toBeTrue()
        ->and($roomRows['Slaapkamer 1']['existing_installation_remains_confirmed_by_installer'])->toBeFalse()
        ->and(collect($dossier['installation_options'])->pluck('label')->all())->toContain('Multi-split twee slaapkamers')
        ->and($payload->input_hash)->not->toBeEmpty();
});

test('report stores generation time and dossier hash and renders the time', function () {
    [, $intake] = n69IntakeWithSelectedBedroomsOnly();
    $intake = n69CompletedWithReport($intake);

    app(SummarizeIntake::class)->handle($intake);

    $report = $intake->fresh()->report;
    expect($report->meta['ai_summary']['generated_at'] ?? null)->not->toBeNull()
        ->and($report->meta['ai_dossier_hash'] ?? null)->toBe(app(SummaryDossierContext::class)->hash($intake->fresh()))
        ->and($report->html)->toContain('Opgesteld op');
});

test('a dossier change after the summary re-queues the summary once, an unchanged dossier does not', function () {
    [$user, $intake, $living] = n69IntakeWithSelectedBedroomsOnly();
    $intake = n69CompletedWithReport($intake);
    app(SummarizeIntake::class)->handle($intake);

    Queue::fake();
    $context = app(SummaryDossierContext::class);
    expect($context->dispatchIfStale($intake->fresh()))->toBeFalse();
    Queue::assertNothingPushed();

    // Installer decision (N68) changes the dossier -> summary is stale.
    app(AircoSurveyService::class)->confirmRoomExistingInstallation($intake->fresh(), $user, $living);

    Queue::assertPushed(SummarizeIntakeJob::class, 1);
});

test('no re-queue without an existing ai summary or when the intake is not completed', function () {
    [, $intake] = n69IntakeWithSelectedBedroomsOnly();
    Queue::fake();

    $context = app(SummaryDossierContext::class);
    expect($context->dispatchIfStale(n69CompletedWithReport($intake)))->toBeFalse();

    $intake->update(['status' => IntakeStatus::Sent]);
    expect($context->dispatchIfStale($intake->fresh()))->toBeFalse();
    Queue::assertNothingPushed();
});

function n69Record(Intake $intake, DossierSubject $subject, string $key, mixed $value, DossierRecordStatus $status, string $source = 'ai'): void
{
    DossierRecord::query()->create([
        'intake_id' => $intake->id,
        'company_id' => $intake->company_id,
        'dossier_subject_id' => $subject->id,
        'kind' => DossierRecordKind::Observation,
        'key' => $key,
        'value' => $value,
        'actor_type' => 'system',
        'method' => 'test',
        'observed_at' => now(),
        'source_type' => $source,
        'status' => $status,
    ]);
}

test('dossier records: only established, value capped, installer first, hash over passed set', function () {
    [, $intake] = n69IntakeWithSelectedBedroomsOnly();
    $subject = DossierSubject::query()->create([
        'intake_id' => $intake->id, 'company_id' => $intake->company_id, 'type' => 'survey', 'key' => 'n69-subject', 'label' => 'X',
    ]);
    n69Record($intake, $subject, 'a_ai', 'x', DossierRecordStatus::Established, 'ai');
    n69Record($intake, $subject, 'b_installer', str_repeat('z', 2000), DossierRecordStatus::Established, 'installer');
    n69Record($intake, $subject, 'c_unconfirmed', 'maybe', DossierRecordStatus::Proposed, 'ai');

    $context = app(SummaryDossierContext::class);
    $built = $context->build($intake->fresh());
    $records = collect($built['dossier_records'])->where('subject', 'n69-subject')->values();

    expect($records->pluck('key')->all())->toBe(['b_installer', 'a_ai'])
        ->and(mb_strlen($records[0]['value']))->toBe(300);

    $before = $context->hash($intake->fresh());
    n69Record($intake, $subject, 'd_unconfirmed', 'again', DossierRecordStatus::Proposed, 'ai');
    expect($context->hash($intake->fresh()))->toBe($before);
});

test('a stale summary is queued; a fresh one is not', function () {
    [, $intake] = n69IntakeWithSelectedBedroomsOnly();
    $intake = n69CompletedWithReport($intake);
    $intake->report->update(['meta' => ['ai_summary' => ['summary' => 's'], 'ai_dossier_hash' => 'old']]);

    Queue::fake();
    $context = app(SummaryDossierContext::class);
    expect($context->dispatchIfStale($intake->fresh()))->toBeTrue();

    $intake->report->update(['meta' => ['ai_summary' => ['summary' => 's'], 'ai_dossier_hash' => $context->hash($intake->fresh())]]);
    expect($context->dispatchIfStale($intake->fresh()))->toBeFalse();
    Queue::assertPushed(SummarizeIntakeJob::class, 1);
});

test('summarize job is unique per intake', function () {
    $job = new SummarizeIntakeJob(42);
    expect($job)->toBeInstanceOf(ShouldBeUniqueUntilProcessing::class)
        ->and($job->uniqueId())->toBe('42');
});

test('a pending unique summary job never drops the completion chain', function () {
    Queue::fake();

    // dispatchIfStale path: holds the unique lock until processing, so a second one is dropped.
    SummarizeIntakeJob::dispatch(42);
    SummarizeIntakeJob::dispatch(42);
    Queue::assertPushed(SummarizeIntakeJob::class, 1);

    // CompleteIntake / CompleteFollowUpRound chain: the head is not subject to the lock.
    Bus::chain([
        new SummarizeIntakeJob(42),
        new SuggestAttentionPointsJob(42),
        new SynthesizeSurveyDossierJob(42),
    ])->dispatch();

    Queue::assertPushed(SummarizeIntakeJob::class, 2);
    Queue::assertPushedWithChain(SummarizeIntakeJob::class, [
        SuggestAttentionPointsJob::class,
        SynthesizeSurveyDossierJob::class,
    ]);
});

test('a failing summary dispatch does not skip the pdf refresh', function () {
    [, $intake] = n69IntakeWithSelectedBedroomsOnly();
    $intake = n69CompletedWithReport($intake);
    $intake->report->update(['meta' => ['ai_summary' => ['summary' => 's'], 'ai_dossier_hash' => 'old']]);
    $intake->update(['is_demo' => false]);
    $fake = new class(app()) extends QueueFake
    {
        public function push($job, $data = '', $queue = null)
        {
            if ($job instanceof SummarizeIntakeJob) {
                throw new RuntimeException('queue down');
            }

            return parent::push($job, $data, $queue);
        }
    };
    Queue::swap($fake);

    app(RefreshStoredIntakeReport::class)->handle($intake->fresh());

    Queue::assertPushed(GenerateIntakePdfJob::class);
});
