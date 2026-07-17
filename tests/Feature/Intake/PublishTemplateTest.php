<?php

declare(strict_types=1);

use App\Domains\Intake\Models\IntakeTemplate;
use App\Domains\Intake\Services\PublishIntakeTemplateFromConfig;
use App\Enums\TemplateVersionStatus;
use Database\Seeders\IntakeTemplateSeeder;

test('airco template seeder publishes a stable version', function () {
    $this->seed(IntakeTemplateSeeder::class);

    $template = IntakeTemplate::query()->where('key', 'airco')->first();

    expect($template)->not->toBeNull()
        ->and($template->is_active)->toBeTrue();

    $version = $template->latestPublishedVersion();

    expect($version)->not->toBeNull()
        ->and($version->status)->toBe(TemplateVersionStatus::Published)
        ->and($version->version)->toBe(1)
        ->and($version->sections()->count())->toBeGreaterThan(5)
        ->and($version->sections()->where('key', 'rooms')->value('is_repeatable'))->toBeTrue();

    // Re-seeding is idempotent for the same published version.
    $again = app(PublishIntakeTemplateFromConfig::class)->handle(
        require database_path('data/templates/airco/v1.php'),
    );

    expect($again->id)->toBe($version->id)
        ->and(IntakeTemplate::query()->where('key', 'airco')->count())->toBe(1);
});
