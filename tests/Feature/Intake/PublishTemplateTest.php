<?php

declare(strict_types=1);

use App\Domains\Intake\Models\IntakeTemplate;
use App\Domains\Intake\Services\PublishIntakeTemplateFromConfig;
use App\Enums\TemplateVersionStatus;
use Database\Seeders\IntakeTemplateSeeder;

test('airco template seeder publishes v1 through v5 with v5 as latest', function () {
    $this->seed(IntakeTemplateSeeder::class);

    $template = IntakeTemplate::query()->where('key', 'airco')->first();

    expect($template)->not->toBeNull()
        ->and($template->is_active)->toBeTrue();

    $versions = $template->versions()->orderBy('version')->get();

    expect($versions)->toHaveCount(5)
        ->and($versions->pluck('version')->all())->toBe([1, 2, 3, 4, 5])
        ->and($versions->every(fn ($version) => $version->status === TemplateVersionStatus::Published))->toBeTrue();

    $latest = $template->latestPublishedVersion();

    expect($latest)->not->toBeNull()
        ->and($latest->version)->toBe(5)
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

    $outdoor = $latest->sections()->where('key', 'outdoor_unit')->firstOrFail();
    $facade = $outdoor->questions()->where('key', 'facade_overview_photo')->firstOrFail();
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

    expect($facade->is_required)->toBeFalse()
        ->and($freeGroup->is_required)->toBeFalse()
        ->and($freeGroup->label)->toBe('Is er een vrije groep beschikbaar?')
        ->and($fuseboxPhoto->meta['photo_analysis'] ?? null)->toBe('fusebox')
        ->and($outdoor->questions()->where('key', 'distance_to_indoor')->exists())->toBeFalse();

    // Re-seeding is idempotent for published versions.
    $againV1 = app(PublishIntakeTemplateFromConfig::class)->handle(
        require database_path('data/templates/airco/v1.php'),
    );
    $againV5 = app(PublishIntakeTemplateFromConfig::class)->handle(
        require database_path('data/templates/airco/v5.php'),
    );

    expect($againV1->version)->toBe(1)
        ->and($againV5->id)->toBe($latest->id)
        ->and(IntakeTemplate::query()->where('key', 'airco')->count())->toBe(1)
        ->and($template->versions()->count())->toBe(5);
});
