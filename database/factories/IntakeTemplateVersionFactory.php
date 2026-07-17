<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domains\Intake\Models\IntakeTemplate;
use App\Domains\Intake\Models\IntakeTemplateVersion;
use App\Enums\TemplateVersionStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IntakeTemplateVersion>
 */
class IntakeTemplateVersionFactory extends Factory
{
    protected $model = IntakeTemplateVersion::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'intake_template_id' => IntakeTemplate::factory(),
            'version' => 1,
            'status' => TemplateVersionStatus::Published,
            'published_at' => now(),
            'change_notes' => 'Initial version',
        ];
    }
}
