<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domains\Intake\Services\PublishIntakeTemplateFromConfig;
use Illuminate\Database\Seeder;

class IntakeTemplateSeeder extends Seeder
{
    public function run(): void
    {
        $publisher = app(PublishIntakeTemplateFromConfig::class);

        /** @var list<string> $configs */
        $configs = [
            database_path('data/templates/airco/v1.php'),
            database_path('data/templates/airco/v2.php'),
            database_path('data/templates/airco/v3.php'),
            database_path('data/templates/airco/v4.php'),
            database_path('data/templates/airco/v5.php'),
            database_path('data/templates/airco/v6.php'),
        ];

        foreach ($configs as $path) {
            /** @var array<string, mixed> $config */
            $config = require $path;
            $publisher->handle($config);
        }
    }
}
