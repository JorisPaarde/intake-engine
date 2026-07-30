<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeTemplateVersion;
use App\Enums\ContributionMode;
use App\Enums\IntakeStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Intake>
 */
class IntakeFactory extends Factory
{
    protected $model = Intake::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $houseNumber = fake()->numberBetween(1, 250);

        return [
            'uuid' => (string) Str::uuid(),
            'intake_template_version_id' => IntakeTemplateVersion::factory(),
            'created_by' => User::factory(),
            'status' => IntakeStatus::Sent,
            'workflow_mode' => ContributionMode::Customer,
            'customer_name' => fake()->name(),
            'customer_email' => fake()->safeEmail(),
            'customer_phone' => fake()->optional()->numerify('06########'),
            'address_line' => fake()->streetName().' '.$houseNumber,
            'address_postal_code' => fake()->numerify('####').strtoupper(fake()->randomLetter().fake()->randomLetter()),
            'address_house_number' => $houseNumber,
            'address_house_number_addition' => null,
            'address_city' => fake()->city(),
            'access_token' => Str::random(64),
            'customer_access_enabled' => true,
            'token_expires_at' => now()->addDays(60),
            'internal_note' => null,
            'progress_percent' => 0,
        ];
    }

    public function inProgress(): static
    {
        return $this->state(fn (): array => [
            'status' => IntakeStatus::InProgress,
            'started_at' => now()->subDay(),
            'progress_percent' => 40,
            'current_section_key' => 'building',
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn (): array => [
            'status' => IntakeStatus::Completed,
            'started_at' => now()->subDays(3),
            'completed_at' => now()->subDay(),
            'progress_percent' => 100,
            'current_section_key' => 'closing',
        ]);
    }

    public function revoked(): static
    {
        return $this->state(fn (): array => [
            'status' => IntakeStatus::Cancelled,
            'token_revoked_at' => now(),
        ]);
    }
}
