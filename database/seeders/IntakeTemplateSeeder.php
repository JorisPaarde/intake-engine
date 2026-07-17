<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domains\Intake\Services\PublishIntakeTemplateFromConfig;
use Illuminate\Database\Seeder;

class IntakeTemplateSeeder extends Seeder
{
    public function run(): void
    {
        /** @var array<string, mixed> $config */
        $config = require database_path('data/templates/airco/v1.php');

        app(PublishIntakeTemplateFromConfig::class)->handle($config);
    }
}
