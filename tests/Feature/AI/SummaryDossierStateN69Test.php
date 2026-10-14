<?php

declare(strict_types=1);

use App\Domains\AI\Actions\SummarizeIntake;
use App\Domains\AI\Clients\FakeAiClient;
use App\Domains\AI\Jobs\SummarizeIntakeJob;
use App\Domains\AI\Models\AiRun;
use App\Domains\AI\Services\SummaryDossierContext;
use App\Domains\Intake\Actions\CreateIntake;
use App\Domains\Intake\Models\AircoRoom;
use App\Domains\Intake\Models\GeneratedReport;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Services\AircoSurveyService;
use App\Enums\AircoConfigurationType;
use App\Enums\AircoPlacementType;
use App\Enums\ContributionMode;
use App\Enums\IntakeStatus;
use App\Models\User;
use Database\Seeders\IntakeTemplateSeeder;
use Illuminate\Support\Facades\Queue;

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
