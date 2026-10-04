<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domains\Intake\Services\PublishIntakeTemplateFromConfig;
use Illuminate\Database\Seeder;

class IntakeTemplateSeeder extends Seeder
{
    public const ALL_AIRCO_TEMPLATE_PATHS = [
        'data/templates/airco/v1.php',
        'data/templates/airco/v2.php',
        'data/templates/airco/v3.php',
        'data/templates/airco/v4.php',
        'data/templates/airco/v5.php',
        'data/templates/airco/v6.php',
        'data/templates/airco/v7.php',
        'data/templates/airco/v8.php',
        'data/templates/airco/v9.php',
        'data/templates/airco/v10.php',
        'data/templates/airco/v11.php',
        'data/templates/airco/v12.php',
        'data/templates/airco/v13.php',
        'data/templates/airco/v14.php',
        'data/templates/airco/v15.php',
        'data/templates/airco/v16.php',
        'data/templates/airco/v17.php',
        'data/templates/airco/v18.php',
        'data/templates/airco/v19.php',
        'data/templates/airco/v20.php',
        'data/templates/airco/v21.php',
        'data/templates/airco/v22.php',
        'data/templates/airco/v23.php',
        'data/templates/airco/v24.php',
        'data/templates/airco/v25.php',
        'data/templates/airco/v26.php',
    ];

    public const LATEST_AIRCO_TEMPLATE_PATH = 'data/templates/airco/v26.php';

    public function run(): void
    {
        $publisher = app(PublishIntakeTemplateFromConfig::class);

        foreach ($this->aircoTemplatePaths() as $relativePath) {
            /** @var array<string, mixed> $config */
            $config = require database_path($relativePath);
            $publisher->handle($config);
        }
    }

    /**
     * @return non-empty-list<string>
     */
    private function aircoTemplatePaths(): array
    {
        if (config('intake.seed_latest_template_only')) {
            return [self::LATEST_AIRCO_TEMPLATE_PATH];
        }

        return self::ALL_AIRCO_TEMPLATE_PATHS;
    }
}
