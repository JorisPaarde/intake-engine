<?php

declare(strict_types=1);

use App\Domains\Intake\Models\IntakeTemplate;
use App\Domains\Intake\Services\PublishIntakeTemplateFromConfig;
use App\Enums\TemplateVersionStatus;
use Database\Seeders\IntakeTemplateSeeder;

test('airco template seeder publishes v1 through v8 with v8 as latest', function () {
    $this->seed(IntakeTemplateSeeder::class);

    $template = IntakeTemplate::query()->where('key', 'airco')->first();

    expect($template)->not->toBeNull()
        ->and($template->is_active)->toBeTrue();

    $versions = $template->versions()->orderBy('version')->get();

    expect($versions)->toHaveCount(8)
        ->and($versions->pluck('version')->all())->toBe([1, 2, 3, 4, 5, 6, 7, 8])
        ->and($versions->every(fn ($version) => $version->status === TemplateVersionStatus::Published))->toBeTrue();

    $latest = $template->latestPublishedVersion();

    expect($latest)->not->toBeNull()
        ->and($latest->version)->toBe(8)
        ->and($latest->sections()->count())->toBeGreaterThan(5)
        ->and($latest->sections()->where('key', 'rooms')->value('is_repeatable'))->toBeTrue();

    $roomQuestions = $latest->sections()
        ->where('key', 'rooms')
        ->firstOrFail()
        ->questions()
        ->get();

    $roomKeys = $roomQuestions->pluck('key')->all();

    expect($roomKeys)->toContain('room_size_indication')
        ->and($roomKeys)->not->toContain('room_length_m')
        ->and($roomKeys)->not->toContain('room_width_m')
        ->and($roomKeys)->not->toContain('ceiling_height_m');

    // BL-016 (v3): prefill meta flags flow through the seeder.
    $floorLevel = $roomQuestions->firstWhere('key', 'floor_level');
    expect($floorLevel->meta['prefill_from_previous'] ?? null)->toBeTrue();

    $requestQuestions = $latest->sections()
        ->where('key', 'request')
        ->firstOrFail()
        ->questions()
        ->get();

    $reason = $requestQuestions->firstWhere('key', 'request_reason');
    expect($reason->meta['installer_prefillable'] ?? null)->toBeTrue();

    $buildYear = $latest->sections()
        ->where('key', 'building')
        ->firstOrFail()
        ->questions()
        ->where('key', 'build_year')
        ->firstOrFail();

    expect($buildYear->meta['skip_when_prefilled_by'] ?? null)->toBe('pdok');

    // v8: bouwtype accepteert twee registers, isolatie er één.
    $building = $latest->sections()->where('key', 'building')->firstOrFail();

    expect($building->questions()->where('key', 'building_type')->firstOrFail()->meta['skip_when_prefilled_by'])
        ->toBe(['pdok', 'epo'])
        ->and($building->questions()->where('key', 'insulation_indication')->firstOrFail()->meta['skip_when_prefilled_by'])
        ->toBe(['epo']);

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

    expect($freeGroup->is_required)->toBeFalse()
        ->and($freeGroup->label)->toBe('Is er een vrije groep beschikbaar?')
        ->and($freeGroup->meta['skip_when_prefilled_by'] ?? null)->toBe('ai')
        ->and($fuseboxPhoto->meta['photo_analysis'] ?? null)->toBe('fusebox')
        ->and($outdoor->questions()->where('key', 'distance_to_indoor')->exists())->toBeFalse()
        // v7 schrapt de losse gevelfoto: de PDOK-luchtfoto levert het overzicht al.
        ->and($outdoor->questions()->where('key', 'facade_overview_photo')->exists())->toBeFalse();

    // Re-seeding is idempotent for published versions.
    $againV1 = app(PublishIntakeTemplateFromConfig::class)->handle(
        require database_path('data/templates/airco/v1.php'),
    );
    $againV8 = app(PublishIntakeTemplateFromConfig::class)->handle(
        require database_path('data/templates/airco/v8.php'),
    );

    expect($againV1->version)->toBe(1)
        ->and($againV8->id)->toBe($latest->id)
        ->and(IntakeTemplate::query()->where('key', 'airco')->count())->toBe(1)
        ->and($template->versions()->count())->toBe(8);
});
