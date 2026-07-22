<?php

declare(strict_types=1);

use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeExternalFact;
use App\Domains\Intake\Models\IntakeTemplate;
use App\Domains\Intake\Services\PdokAddressService;
use App\Models\User;
use App\Support\DevAdmin\ServiceStatusReport;
use Database\Seeders\IntakeTemplateSeeder;

function serviceRow(array $services, string $key): array
{
    return collect($services)->firstWhere('key', $key) ?? [];
}

test('report lists every external service with config status', function () {
    $services = app(ServiceStatusReport::class)->services();
    $keys = collect($services)->pluck('key')->all();

    expect($keys)->toContain('pdok', 'kadaster_bag', 'ep_online', 'threedbag', 'ai', 'mail', 'slack');
});

test('services requiring a key report missing configuration', function () {
    config(['services.ep_online.enabled' => true, 'services.ep_online.key' => '']);

    $row = serviceRow(app(ServiceStatusReport::class)->services(), 'ep_online');

    expect($row['requires_key'])->toBeTrue()
        ->and($row['configured'])->toBeFalse();
});

test('geo service reflects the latest stored external fact', function () {
    $this->seed(IntakeTemplateSeeder::class);
    $version = IntakeTemplate::query()->where('key', 'airco')->firstOrFail()->latestPublishedVersion();
    $intake = Intake::factory()->create([
        'created_by' => User::factory()->create()->id,
        'intake_template_version_id' => $version->id,
    ]);

    IntakeExternalFact::query()->create([
        'intake_id' => $intake->id,
        'fact_key' => 'build_year',
        'label' => 'Bouwjaar',
        'value' => ['year' => 1998],
        'source' => PdokAddressService::sourceName(),
        'confidence' => 'high',
        'captured_at' => now(),
    ]);

    $row = serviceRow(app(ServiceStatusReport::class)->services(), 'pdok');

    expect($row['last_at'])->not->toBeNull()
        ->and($row['fact_count'])->toBe(1);
});
