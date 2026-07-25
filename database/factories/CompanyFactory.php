<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Company>
 */
class CompanyFactory extends Factory
{
    protected $model = Company::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->company();

        return [
            'uuid' => (string) Str::uuid(),
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'name' => $name,
            'primary_color' => Company::DEFAULT_PRIMARY,
            'accent_color' => Company::DEFAULT_ACCENT,
            'on_primary_color' => Company::DEFAULT_ON_PRIMARY,
        ];
    }

    public function withLogo(string $contents = 'logo'): static
    {
        return $this->state(fn (array $attributes): array => [
            'logo_disk' => 'local',
            'logo_path' => 'companies/'.($attributes['uuid'] ?? (string) Str::uuid()).'/branding/logo.png',
            'logo_original_filename' => 'logo.png',
            'logo_mime_type' => 'image/png',
            'logo_size_bytes' => strlen($contents),
        ]);
    }
}
