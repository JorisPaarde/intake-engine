<?php

declare(strict_types=1);

use App\Domains\Intake\Actions\CreateIntake;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Services\AircoSurveyService;
use App\Domains\Intake\Services\DossierOverviewBuilder;
use App\Enums\AircoPlacementType;
use App\Enums\ContributionMode;
use App\Models\User;
use Database\Seeders\IntakeTemplateSeeder;

beforeEach(function () {
    $this->seed(IntakeTemplateSeeder::class);
});

function makeSurveyIntakeForProgress(User $user): Intake
{
    return app(CreateIntake::class)->handle($user, [
        'template_key' => 'airco',
        'workflow_mode' => ContributionMode::Installer,
        'customer_name' => 'Voortgang Test',
        'customer_email' => 'voortgang@example.com',
        'address_line' => 'Testlaan 10',
        'address_postal_code' => '1000AA',
        'address_house_number' => 10,
        'address_city' => 'Amsterdam',
    ]);
}

it('counts rooms and placements as filled content without faking klaar-voor-offerte', function () {
    $user = User::factory()->create();
    $intake = makeSurveyIntakeForProgress($user);
    $survey = app(AircoSurveyService::class);
    $overview = app(DossierOverviewBuilder::class);

    $empty = $overview->build($intake);
    expect($empty['filled_count'])->toBe(0)
        ->and($empty['ready_count'])->toBe(0)
        ->and($empty['total_count'])->toBe(8);

    $survey->createRoom($intake, $user, [
        'name' => 'Slaapkamer 1',
        'use_type' => 'bedroom',
    ]);
    $intake->refresh();

    $withRooms = $overview->build($intake);
    expect($withRooms['filled_count'])->toBeGreaterThanOrEqual(2)
        ->and($withRooms['ready_count'])->toBe(1)
        ->and($withRooms['filled_count'])->toBeGreaterThan($withRooms['ready_count']);

    $survey->createPlacement($intake, $user, [
        'type' => AircoPlacementType::PowerSource,
        'label' => 'Meterkast',
    ]);
    $intake->refresh();

    $withPlacement = $overview->build($intake);
    expect($withPlacement['filled_count'])->toBeGreaterThanOrEqual(3)
        ->and($withPlacement['ready_count'])->toBe(1)
        ->and($withPlacement['filled_count'])->toBeLessThan($withPlacement['total_count']);
});
