<?php

declare(strict_types=1);

use App\Domains\Intake\Models\AircoRoom;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeTemplate;
use App\Domains\Intake\Services\AircoSurveyService;
use App\Domains\Intake\Services\DecisionReadinessService;
use App\Domains\Intake\Support\RoomAreaAcceptance;
use App\Domains\Intake\Support\RoomDimensions;
use App\Domains\Intake\Support\RoomHeightRequirement;
use App\Enums\DecisionAreaStatus;
use App\Enums\IntakeStatus;
use App\Models\User;
use Database\Seeders\IntakeTemplateSeeder;

beforeEach(function () {
    $this->seed(IntakeTemplateSeeder::class);
});

function makeFloorAreaIntake(): Intake
{
    $user = User::factory()->create();
    $version = IntakeTemplate::query()
        ->where('key', 'airco')
        ->firstOrFail()
        ->latestPublishedVersion();

    return Intake::factory()->create([
        'created_by' => $user->id,
        'intake_template_version_id' => $version->id,
        'status' => IntakeStatus::InProgress,
        'customer_email' => 'floor-area@example.com',
    ]);
}

test('length and width alone provide a reliable floor area without inventing sides from m2', function () {
    $dimensions = RoomDimensions::from([
        'length_m' => 5.0,
        'width_m' => 4.0,
    ]);

    expect($dimensions->hasReliableFloorArea())->toBeTrue()
        ->and($dimensions->floorBasis())->toBe('length_width')
        ->and($dimensions->effectiveFloorAreaM2())->toBe(20.0)
        ->and($dimensions->lengthM())->toBe(5.0)
        ->and($dimensions->widthM())->toBe(4.0);
});

test('trusted area_m2 alone completes floor area without inventing length or width', function () {
    $dimensions = RoomDimensions::from([
        'area_m2' => 20.0,
        'area_source' => 'installer',
        'area_confidence' => 'high',
    ]);

    expect($dimensions->hasReliableFloorArea())->toBeTrue()
        ->and($dimensions->floorBasis())->toBe('area_m2')
        ->and($dimensions->effectiveFloorAreaM2())->toBe(20.0)
        ->and($dimensions->lengthM())->toBeNull()
        ->and($dimensions->widthM())->toBeNull();
});

test('low confidence AI area_m2 is not trusted for capacity', function () {
    $dimensions = RoomDimensions::from([
        'area_m2' => 18.0,
        'area_source' => 'ai_suggestion',
        'area_confidence' => 'medium',
        'area_evidence' => 'ongeveer 18 vierkante meter',
    ]);

    expect($dimensions->hasTrustedAreaM2())->toBeFalse()
        ->and($dimensions->hasUntrustedAreaM2())->toBeTrue()
        ->and($dimensions->hasReliableFloorArea())->toBeFalse()
        ->and($dimensions->floorBasis())->toBe('untrusted_area');
});

test('AI exact area acceptance requires high confidence evidence and plausible size', function () {
    expect(RoomAreaAcceptance::acceptsAiExactArea('high', 'slaapkamer is 20 m²', 20.0))->toBeTrue()
        ->and(RoomAreaAcceptance::acceptsAiExactArea('medium', 'slaapkamer is 20 m²', 20.0))->toBeFalse()
        ->and(RoomAreaAcceptance::acceptsAiExactArea('high', null, 20.0))->toBeFalse()
        ->and(RoomAreaAcceptance::acceptsAiExactArea('high', '20 m²', 0.5))->toBeFalse()
        ->and(RoomAreaAcceptance::acceptsAiExactArea('high', '20 m²', 20.0, 35.0))->toBeFalse();
});

test('conflicting length times width versus area_m2 is a control point', function () {
    $dimensions = RoomDimensions::from([
        'length_m' => 5.0,
        'width_m' => 4.0,
        'area_m2' => 12.0,
        'area_source' => 'customer',
        'area_confidence' => 'high',
    ]);

    expect($dimensions->hasFloorAreaConflict())->toBeTrue()
        ->and($dimensions->hasReliableFloorArea())->toBeFalse()
        ->and($dimensions->floorBasis())->toBe('conflict')
        ->and($dimensions->effectiveFloorAreaM2())->toBeNull();
});

test('matching length times width and area_m2 are not a conflict', function () {
    $dimensions = RoomDimensions::from([
        'length_m' => 5.0,
        'width_m' => 4.0,
        'area_m2' => 20.0,
        'area_source' => 'customer',
        'area_confidence' => 'high',
    ]);

    expect($dimensions->hasFloorAreaConflict())->toBeFalse()
        ->and($dimensions->hasReliableFloorArea())->toBeTrue()
        ->and($dimensions->floorBasis())->toBe('length_width');
});

test('height is not required by default but attic rooms need it', function () {
    $requirement = new RoomHeightRequirement;
    $living = (new AircoRoom)->forceFill([
        'use_type' => 'living_room',
        'dimensions' => ['length_m' => 5, 'width_m' => 4],
    ]);
    $attic = (new AircoRoom)->forceFill([
        'use_type' => 'attic',
        'dimensions' => ['length_m' => 5, 'width_m' => 4],
    ]);
    $atticWithHeight = (new AircoRoom)->forceFill([
        'use_type' => 'attic',
        'dimensions' => ['length_m' => 5, 'width_m' => 4, 'height_m' => 2.4],
    ]);

    expect($requirement->isRequired($living))->toBeFalse()
        ->and($requirement->missingRequiredHeight($living))->toBeFalse()
        ->and($requirement->isRequired($attic))->toBeTrue()
        ->and($requirement->missingRequiredHeight($attic))->toBeTrue()
        ->and($requirement->missingRequiredHeight($atticWithHeight))->toBeFalse();
});

test('capacity readiness accepts LxB without height and trusted area_m2', function () {
    $intake = makeFloorAreaIntake();
    $user = User::query()->findOrFail($intake->created_by);
    $survey = app(AircoSurveyService::class);

    $survey->createRoom($intake, $user, [
        'name' => 'Woonkamer',
        'use_type' => 'living_room',
        'length_m' => 5.0,
        'width_m' => 4.0,
    ]);
    $survey->createRoom($intake, $user, [
        'name' => 'Slaapkamer',
        'use_type' => 'bedroom',
        'area_m2' => 16.0,
    ]);

    $capacity = $intake->fresh()->decisionAreas()->where('key', 'capacity')->firstOrFail();

    expect($capacity->status)->toBe(DecisionAreaStatus::Ready);
});

test('capacity readiness reviews conflicting LxB versus m2 and low confidence area', function () {
    $intake = makeFloorAreaIntake();
    $user = User::query()->findOrFail($intake->created_by);
    $survey = app(AircoSurveyService::class);

    $survey->createRoom($intake, $user, [
        'name' => 'Kamer conflict',
        'use_type' => 'bedroom',
        'length_m' => 5.0,
        'width_m' => 4.0,
        'area_m2' => 10.0,
    ]);

    $capacity = $intake->fresh()->decisionAreas()->where('key', 'capacity')->firstOrFail();

    expect($capacity->status)->toBe(DecisionAreaStatus::Review)
        ->and($capacity->blocker)->toContain('niet overeen');

    $intake->aircoRooms()->delete();
    $weak = $survey->createRoom($intake, $user, [
        'name' => 'Kamer zwak',
        'use_type' => 'bedroom',
    ]);
    $weak->update([
        'dimensions' => [
            'area_m2' => 14.0,
            'area_source' => 'ai_suggestion',
            'area_confidence' => 'medium',
            'area_evidence' => 'ongeveer 14 m²',
        ],
    ]);

    app(DecisionReadinessService::class)->recalculate($intake->fresh() ?? $intake);
    $capacity = $intake->decisionAreas()->where('key', 'capacity')->firstOrFail();

    expect($capacity->status)->toBe(DecisionAreaStatus::Review)
        ->and($capacity->blocker)->toContain('niet betrouwbaar');
});

test('capacity readiness asks for height only when an explicit rule needs it', function () {
    $intake = makeFloorAreaIntake();
    $user = User::query()->findOrFail($intake->created_by);
    $survey = app(AircoSurveyService::class);

    $survey->createRoom($intake, $user, [
        'name' => 'Zolderkamer',
        'use_type' => 'attic',
        'length_m' => 4.0,
        'width_m' => 3.0,
    ]);

    $capacity = $intake->fresh()->decisionAreas()->where('key', 'capacity')->firstOrFail();

    expect($capacity->status)->toBe(DecisionAreaStatus::Review)
        ->and($capacity->blocker)->toContain('plafondhoogte');

    $room = $intake->fresh()->aircoRooms->firstOrFail();
    $survey->updateRoom($intake, $user, $room, [
        'name' => 'Zolderkamer',
        'use_type' => 'attic',
        'length_m' => 4.0,
        'width_m' => 3.0,
        'height_m' => 2.3,
    ]);

    $capacity = $intake->fresh()->decisionAreas()->where('key', 'capacity')->firstOrFail();

    expect($capacity->status)->toBe(DecisionAreaStatus::Ready);
});
