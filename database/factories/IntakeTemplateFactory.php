<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domains\Intake\Models\IntakeTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IntakeTemplate>
 */
class IntakeTemplateFactory extends Factory
{
    protected $model = IntakeTemplate::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'key' => 'airco-'.fake()->unique()->slug(2),
            'name' => 'Airco-opname',
            'description' => 'Digitale airco-intake',
            'is_active' => true,
        ];
    }
}
