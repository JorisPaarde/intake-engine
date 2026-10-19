<?php

declare(strict_types=1);

use App\Domains\AI\Jobs\SuggestAttentionPointsJob;
use App\Domains\AI\Services\IntakeAttentionContextBuilder;
use App\Domains\Intake\Actions\CreateIntake;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Services\AircoSurveyService;
use App\Enums\ContributionMode;
use App\Enums\IntakeStatus;
use App\Models\User;
use Database\Seeders\IntakeTemplateSeeder;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->seed(IntakeTemplateSeeder::class);
});

/** @return array{0: User, 1: Intake} */
function n74AiIntake(IntakeStatus $status): array
{
    $user = User::factory()->create();
    $intake = app(CreateIntake::class)->handle($user, [
        'template_key' => 'airco',
        'workflow_mode' => ContributionMode::Installer,
        'customer_name' => 'Scope AI',
        'customer_email' => 'scope-ai@example.com',
        'address_line' => 'Testlaan 11',
        'address_postal_code' => '1000AA',
        'address_house_number' => 11,
        'address_city' => 'Amsterdam',
    ]);
    $intake->forceFill(['status' => $status, 'completed_at' => $status === IntakeStatus::Completed ? now() : null])->save();

    return [$user, $intake->fresh()];
}

test('N74: scopewijziging op een afgeronde opname leidt de AI-aandachtspunten opnieuw af', function () {
    [$user, $intake] = n74AiIntake(IntakeStatus::Completed);
    $survey = app(AircoSurveyService::class);
    $attic = $survey->createRoom($intake, $user, ['name' => 'Slaapkamer 2, zolder', 'use_type' => 'bedroom']);

    Queue::fake();
    $survey->confirmRoomExistingInstallation($intake->fresh(), $user, $attic);

    Queue::assertPushed(SuggestAttentionPointsJob::class, fn (SuggestAttentionPointsJob $job): bool => $job->intakeId === $intake->id);
});

test('N74: scopewijziging op een nog lopende opname start geen AI-analyse', function () {
    [$user, $intake] = n74AiIntake(IntakeStatus::Sent);
    $survey = app(AircoSurveyService::class);
    $attic = $survey->createRoom($intake, $user, ['name' => 'Slaapkamer 2, zolder', 'use_type' => 'bedroom']);

    Queue::fake();
    $survey->confirmRoomExistingInstallation($intake->fresh(), $user, $attic);

    Queue::assertNotPushed(SuggestAttentionPointsJob::class);
});

test('N74: de AI-context bevat per ruimte of de bestaande installatie blijft', function () {
    [$user, $intake] = n74AiIntake(IntakeStatus::Completed);
    $survey = app(AircoSurveyService::class);
    $survey->createRoom($intake, $user, ['name' => 'Woonkamer', 'use_type' => 'living_room']);
    $attic = $survey->createRoom($intake, $user, ['name' => 'Slaapkamer 2, zolder', 'use_type' => 'bedroom']);
    Queue::fake();
    $survey->confirmRoomExistingInstallation($intake->fresh(), $user, $attic);

    $rooms = app(IntakeAttentionContextBuilder::class)->build($intake->fresh())['rooms'];

    expect($rooms)->toBe([
        ['name' => 'Woonkamer', 'existing_installation_remains_confirmed_by_installer' => false],
        ['name' => 'Slaapkamer 2, zolder', 'existing_installation_remains_confirmed_by_installer' => true],
    ]);
});
