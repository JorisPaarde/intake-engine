<?php

declare(strict_types=1);

use App\Domains\Intake\Models\IntakeTemplate;
use App\Domains\Intake\Services\PublishIntakeTemplateFromConfig;
use App\Enums\TemplateVersionStatus;

test('airco template seeder publishes v1 through v27 with v27 as latest', function () {
    seedAllAircoTemplateVersions();

    $template = IntakeTemplate::query()->where('key', 'airco')->first();

    expect($template)->not->toBeNull()
        ->and($template->is_active)->toBeTrue();

    $versions = $template->versions()->orderBy('version')->get();

    expect($versions)->toHaveCount(27)
        ->and($versions->pluck('version')->all())->toBe([1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16, 17, 18, 19, 20, 21, 22, 23, 24, 25, 26, 27])
        ->and($versions->every(fn ($version) => $version->status === TemplateVersionStatus::Published))->toBeTrue();

    $latest = $template->latestPublishedVersion();

    expect($latest)->not->toBeNull()
        ->and($latest->version)->toBe(27)
        ->and($latest->sections()->count())->toBeGreaterThan(5)
        ->and($latest->sections()->where('key', 'rooms')->value('is_repeatable'))->toBeTrue();

    $roomQuestions = $latest->sections()
        ->where('key', 'rooms')
        ->firstOrFail()
        ->questions()
        ->get();

    $roomKeys = $roomQuestions->pluck('key')->all();

    expect($roomKeys)->toContain('room_size_indication')
        ->and($roomKeys)->toContain('room_length_m')
        ->and($roomKeys)->toContain('preferred_indoor_location')
        ->and($roomKeys)->toContain('glazing_type')
        ->and($roomKeys)->toContain('indoor_unit_position_photo')
        ->and($roomKeys)->toContain('room_width_m')
        ->and($roomKeys)->toContain('room_area_m2')
        ->and($roomKeys)->toContain('ceiling_height_m')
        ->and($roomKeys)->toContain('room_name')
        ->and($roomKeys)->toContain('wall_outlet_photo')
        ->and($roomKeys)->toContain('room_extra_overview_needed')
        ->and($roomKeys[0])->toBe('room_photos')
        ->and(array_search('room_photos', $roomKeys, true))->toBeLessThan(array_search('room_length_m', $roomKeys, true))
        ->and(array_search('room_photos', $roomKeys, true))->toBeLessThan(array_search('sun_exposure', $roomKeys, true))
        ->and($roomQuestions->firstWhere('key', 'room_length_m')->is_required)->toBeFalse()
        ->and($roomQuestions->firstWhere('key', 'room_area_m2')->is_required)->toBeFalse()
        ->and($roomQuestions->firstWhere('key', 'room_length_m')->label)->toBe('Lengte (m)')
        ->and($roomQuestions->firstWhere('key', 'room_width_m')->label)->toBe('Breedte (m)')
        ->and($roomQuestions->firstWhere('key', 'room_area_m2')->label)->toBe('Vloeroppervlak (m²)')
        ->and($roomQuestions->firstWhere('key', 'ceiling_height_m')->label)->toBe('Hoogte (m)')
        ->and($roomQuestions->firstWhere('key', 'room_area_m2')->help_text)->toContain('lengte en breedte')
        ->and($roomQuestions->firstWhere('key', 'ceiling_height_m')->help_text)->toContain('plafondhoogte')
        ->and($roomQuestions->firstWhere('key', 'room_length_m')->meta['skip_when_prefilled_by'] ?? [])->toContain('ai')
        ->and($roomQuestions->firstWhere('key', 'room_length_m')->meta['skip_when_prefilled_by'] ?? [])->toContain('installer')
        ->and($roomQuestions->firstWhere('key', 'room_name')->meta['skip_when_prefilled_by'] ?? [])->toContain('ai');

    // Sectievolgorde: ruimtes (foto’s) vóór woningvragen.
    $sectionKeys = $latest->sections()->orderBy('sort_order')->pluck('key')->all();
    expect(array_search('rooms', $sectionKeys, true))->toBeLessThan(array_search('building', $sectionKeys, true))
        ->and(array_search('outdoor_unit', $sectionKeys, true))->toBeLessThan(array_search('building', $sectionKeys, true));

    // BL-016 (v3): prefill meta flags flow through the seeder.
    $floorLevel = $roomQuestions->firstWhere('key', 'floor_level');
    expect($floorLevel->meta['prefill_from_previous'] ?? null)->toBeTrue();

    $requestQuestions = $latest->sections()
        ->where('key', 'request')
        ->firstOrFail()
        ->questions()
        ->get();

    $reason = $requestQuestions->firstWhere('key', 'request_reason');
    $desiredRoomCount = $requestQuestions->firstWhere('key', 'indoor_unit_count');
    $roomPositionPhoto = $latest->sections()
        ->where('key', 'rooms')
        ->firstOrFail()
        ->questions()
        ->where('key', 'indoor_unit_position_photo')
        ->firstOrFail();
    expect($reason->meta['installer_prefillable'] ?? null)->toBeTrue()
        ->and($desiredRoomCount->label)->toBe('Hoeveel ruimtes wil je koelen of verwarmen?')
        ->and($roomPositionPhoto->label)->toBe('Extra foto: ontbrekende wand of deur')
        ->and($roomPositionPhoto->is_required)->toBeTrue()
        ->and($roomPositionPhoto->rules()->where('effect', 'show')->count())->toBeGreaterThan(0)
        ->and($roomPositionPhoto->help_text)->toContain('Je hoeft zelf geen plek');

    $pipeRoute = $latest->sections()
        ->where('key', 'pipe_route')
        ->firstOrFail()
        ->questions()
        ->where('key', 'pipe_route_photos')
        ->firstOrFail();
    expect($pipeRoute->is_required)->toBeFalse()
        ->and($pipeRoute->meta['audience'] ?? null)->toBe('installer')
        ->and($roomQuestions->firstWhere('key', 'room_length_m')->meta['wizard_group'] ?? null)->toBe('room_dimensions')
        ->and($roomQuestions->firstWhere('key', 'room_width_m')->meta['wizard_group'] ?? null)->toBe('room_dimensions');

    $buildYear = $latest->sections()
        ->where('key', 'building')
        ->firstOrFail()
        ->questions()
        ->where('key', 'build_year')
        ->firstOrFail();

    expect($buildYear->meta['skip_when_prefilled_by'] ?? null)->toBe('pdok');

    // v8: bouwtype accepteert twee registers, isolatie er één.
    $building = $latest->sections()->where('key', 'building')->firstOrFail();
    $crawlSpace = $building->questions()->where('key', 'crawl_space_present')->firstOrFail();

    expect($building->questions()->where('key', 'building_type')->firstOrFail()->meta['skip_when_prefilled_by'])
        ->toBe(['pdok', 'epo'])
        ->and($building->questions()->where('key', 'insulation_indication')->firstOrFail()->meta['skip_when_prefilled_by'])
        ->toContain('epo')
        ->and($building->questions()->where('key', 'insulation_indication')->firstOrFail()->meta['skip_when_prefilled_by'])
        ->toContain('ai')
        ->and($crawlSpace->label)->toBe('Is er een kruipruimte?')
        ->and($crawlSpace->help_text)->toBeNull()
        ->and($building->questions()->where('key', 'floor_insulation')->firstOrFail()->meta['skip_when_prefilled_by'])
        ->toContain('epo');

    $outdoor = $latest->sections()->where('key', 'outdoor_unit')->firstOrFail();
    $freeGroup = $latest->sections()
        ->where('key', 'electrical')
        ->firstOrFail()
        ->questions()
        ->where('key', 'free_group_known')
        ->firstOrFail();
    $fuseboxPhoto = $latest->sections()
        ->where('key', 'electrical')
        ->firstOrFail()
        ->questions()
        ->where('key', 'fusebox_photo')
        ->firstOrFail();
    $aroundHouse = $outdoor->questions()->where('key', 'around_house_photos')->firstOrFail();

    $naturalFall = $latest->sections()
        ->where('key', 'condensate')
        ->firstOrFail()
        ->questions()
        ->where('key', 'natural_fall_possible')
        ->firstOrFail();
    $pipeRoute = $latest->sections()
        ->where('key', 'pipe_route')
        ->firstOrFail()
        ->questions()
        ->where('key', 'pipe_route_description')
        ->firstOrFail();
    $drillings = $latest->sections()
        ->where('key', 'pipe_route')
        ->firstOrFail()
        ->questions()
        ->where('key', 'drillings_needed')
        ->firstOrFail();
    $drainPhoto = $latest->sections()
        ->where('key', 'condensate')
        ->firstOrFail()
        ->questions()
        ->where('key', 'drain_photo')
        ->firstOrFail();

    expect($freeGroup->is_required)->toBeTrue()
        ->and($freeGroup->meta['installer_decision'] ?? null)->toBeNull()
        ->and($freeGroup->label)->toBe('Is er al een aparte vrije stroomgroep beschikbaar?')
        ->and($freeGroup->meta['skip_when_prefilled_by'] ?? [])->toContain('ai')
        ->and($freeGroup->meta['skip_when_prefilled_by'] ?? [])->toContain('ai_photo')
        ->and($freeGroup->rules)->toHaveCount(1)
        ->and($freeGroup->rules->first()->source_question_key)->toBe('fusebox_photo')
        ->and($naturalFall->meta['installer_decision'] ?? null)->toBeNull()
        ->and($pipeRoute->meta['installer_decision'] ?? null)->toBeNull()
        ->and($drillings->meta['installer_decision'] ?? null)->toBeNull()
        ->and($drainPhoto->label)->toContain('optioneel')
        ->and($drainPhoto->is_required)->toBeFalse()
        ->and($drainPhoto->rules)->toBeEmpty()
        ->and($drainPhoto->meta['wizard_group'] ?? null)->toBe('drain_nearby')
        ->and($drainPhoto->meta['allow_skip'] ?? null)->toBeNull()
        ->and($drainPhoto->meta['reuse_from_photo_keys'] ?? null)->toBeNull()
        ->and($aroundHouse->meta['allow_skip'] ?? null)->toBeTrue()
        ->and($aroundHouse->meta['reuse_from_photo_keys'] ?? [])->toContain('outdoor_location_photos')
        ->and($fuseboxPhoto->meta['photo_analysis'] ?? null)->toBe('fusebox')
        ->and($fuseboxPhoto->is_required)->toBeTrue()
        ->and($aroundHouse->is_required)->toBeTrue()
        ->and($outdoor->questions()->where('key', 'distance_to_indoor')->exists())->toBeFalse()
        // v7 schrapt de losse gevelfoto: de PDOK-luchtfoto levert het overzicht al.
        ->and($outdoor->questions()->where('key', 'facade_overview_photo')->exists())->toBeFalse()
        // v15: dakkapel als expliciete buitenunitplek (geen regex-heuristiek).
        ->and(
            $outdoor->questions()
                ->where('key', 'outdoor_location')
                ->firstOrFail()
                ->options()
                ->where('value', 'dormer')
                ->value('label'),
        )->toBe('Op of aan de dakkapel');

    $drainLocation = $latest->sections()
        ->where('key', 'condensate')
        ->firstOrFail()
        ->questions()
        ->where('key', 'drain_location')
        ->firstOrFail();
    $outdoorMount = $outdoor->questions()->where('key', 'outdoor_mount_type')->firstOrFail();

    expect($drainLocation->is_required)->toBeFalse()
        ->and($drainLocation->meta['wizard_group'] ?? null)->toBe('drain_nearby')
        ->and($drainLocation->label)->toBe('Afvoer in de buurt')
        ->and($drainLocation->options()->pluck('value')->all())->toBe([
            'outside_nearby',
            'indoor_nearby',
            'unknown',
        ])
        ->and($outdoorMount->is_required)->toBeFalse()
        ->and($outdoorMount->options()->where('value', 'unknown')->value('label'))->toBe('Geen voorkeur')
        ->and($outdoorMount->help_text)->toContain('installateur');

    $sunExposure = $latest->sections()
        ->where('key', 'rooms')
        ->firstOrFail()
        ->questions()
        ->where('key', 'sun_exposure')
        ->firstOrFail();

    expect($sunExposure->label)->toBe('Hoeveel zon krijgt deze ruimte?')
        ->and($sunExposure->options()->pluck('value')->all())->toContain('unknown');

    $glassAmount = $latest->sections()
        ->where('key', 'rooms')
        ->firstOrFail()
        ->questions()
        ->where('key', 'glass_amount')
        ->firstOrFail();
    $glazingType = $latest->sections()
        ->where('key', 'rooms')
        ->firstOrFail()
        ->questions()
        ->where('key', 'glazing_type')
        ->firstOrFail();

    expect($glassAmount->options()->pluck('value')->all())->toContain('unknown')
        ->and($glazingType->options()->pluck('value')->all())->toBe([
            'single',
            'double',
            'hr_plus_plus',
            'unknown',
        ]);

    // Re-seeding is idempotent for published versions.
    $againV1 = app(PublishIntakeTemplateFromConfig::class)->handle(
        require database_path('data/templates/airco/v1.php'),
    );
    $againLatest = app(PublishIntakeTemplateFromConfig::class)->handle(
        require database_path('data/templates/airco/v27.php'),
    );

    expect($againV1->version)->toBe(1)
        ->and($againLatest->id)->toBe($latest->id)
        ->and(IntakeTemplate::query()->where('key', 'airco')->count())->toBe(1)
        ->and($template->versions()->count())->toBe(27);
});
