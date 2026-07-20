<?php

declare(strict_types=1);

use App\Domains\Intake\Models\Intake;
use Database\Seeders\DatabaseSeeder;

test('database seeder creates demo intakes with UUIDs while model events are disabled', function () {
    $this->seed(DatabaseSeeder::class);

    $intakes = Intake::query()->orderBy('id')->get();

    expect($intakes)->toHaveCount(3)
        ->and($intakes->pluck('uuid')->filter()->unique())->toHaveCount(3)
        ->and($intakes->every(fn (Intake $intake): bool => strlen($intake->uuid) === 36))->toBeTrue();
});
