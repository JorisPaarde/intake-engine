<?php

declare(strict_types=1);

use App\Domains\Intake\Models\IntakeTemplate;
use App\Domains\Intake\Services\PublishIntakeTemplateFromConfig;
use App\Enums\TemplateVersionStatus;
use Database\Seeders\IntakeTemplateSeeder;

test('airco template seeder publishes v1, v2 and v3 with v3 as latest', function () {
    $this->seed(IntakeTemplateSeeder::class);

    $template = IntakeTemplate::query()->where('key', 'airco')->first();

    expect($template)->not->toBeNull()
        ->and($template->is_active)->toBeTrue();

    $versions = $template->versions()->orderBy('version')->get();

    expect($versions)->toHaveCount(3)
        ->and($versions->pluck('version')->all())->toBe([1, 2, 3])
        ->and($versions->every(fn ($version) => $version->status === TemplateVersionStatus::Published))->toBeTrue();

    $latest = $template->latestPublishedVersion();

    expect($latest)->not->toBeNull()
        ->and($latest->version)->toBe(3)
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

    $outdoor = $latest->sections()->where('key', 'outdoor_unit')->firstOrFail();
    $facade = $outdoor->questions()->where('key', 'facade_overview_photo')->firstOrFail();
    $freeGroup = $latest->sections()
        ->where('key', 'electrical')
        ->firstOrFail()
        ->questions()
        ->where('key', 'free_group_known')
        ->firstOrFail();

    expect($facade->is_required)->toBeFalse()
        ->and($freeGroup->is_required)->toBeFalse()
        ->and($outdoor->questions()->where('key', 'distance_to_indoor')->exists())->toBeFalse();

    // Re-seeding is idempotent for published versions.
    $againV1 = app(PublishIntakeTemplateFromConfig::class)->handle(
        require database_path('data/templates/airco/v1.php'),
    );
    $againV3 = app(PublishIntakeTemplateFromConfig::class)->handle(
        require database_path('data/templates/airco/v3.php'),
    );

    expect($againV1->version)->toBe(1)
        ->and($againV3->id)->toBe($latest->id)
        ->and(IntakeTemplate::query()->where('key', 'airco')->count())->toBe(1)
        ->and($template->versions()->count())->toBe(3);
});
