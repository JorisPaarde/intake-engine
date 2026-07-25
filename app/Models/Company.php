<?php

declare(strict_types=1);

namespace App\Models;

use App\Domains\Intake\Models\Intake;
use Database\Factories\CompanyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Fillable([
    'uuid',
    'slug',
    'name',
    'logo_disk',
    'logo_path',
    'logo_original_filename',
    'logo_mime_type',
    'logo_size_bytes',
    'primary_color',
    'accent_color',
    'on_primary_color',
])]
class Company extends Model
{
    /** @use HasFactory<CompanyFactory> */
    use HasFactory;

    public const DEFAULT_PRIMARY = '#0071E3';

    public const DEFAULT_ACCENT = '#005EC0';

    public const DEFAULT_ON_PRIMARY = '#FFFFFF';

    protected static function booted(): void
    {
        static::creating(function (Company $company): void {
            if (! isset($company->attributes['uuid'])) {
                $company->uuid = (string) Str::uuid();
            }

            if (! isset($company->attributes['slug']) || $company->slug === '') {
                $company->slug = self::generateSlug((string) $company->name, (string) $company->uuid);
            }
        });

        static::saving(function (Company $company): void {
            $company->primary_color = static::normalizeHex($company->primary_color) ?? self::DEFAULT_PRIMARY;
            $company->accent_color = static::normalizeHex($company->accent_color) ?? self::DEFAULT_ACCENT;
            $company->on_primary_color = static::normalizeHex($company->on_primary_color) ?? self::DEFAULT_ON_PRIMARY;
        });
    }

    protected static function newFactory(): CompanyFactory
    {
        return CompanyFactory::new();
    }

    /** @return HasMany<User, $this> */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /** @return HasMany<Intake, $this> */
    public function intakes(): HasMany
    {
        return $this->hasMany(Intake::class);
    }

    public function hasLogo(): bool
    {
        return is_string($this->logo_disk)
            && $this->logo_disk !== ''
            && is_string($this->logo_path)
            && $this->logo_path !== '';
    }

    /**
     * @return array{primary: string, accent: string, on_primary: string}
     */
    public function themeTokens(): array
    {
        return [
            'primary' => static::normalizeHex($this->primary_color) ?? self::DEFAULT_PRIMARY,
            'accent' => static::normalizeHex($this->accent_color) ?? self::DEFAULT_ACCENT,
            'on_primary' => static::normalizeHex($this->on_primary_color) ?? self::DEFAULT_ON_PRIMARY,
        ];
    }

    /**
     * Canonical fallback for requests without a resolved tenant.
     *
     * @return array{primary: string, accent: string, on_primary: string}
     */
    public static function defaultThemeTokens(): array
    {
        return [
            'primary' => self::DEFAULT_PRIMARY,
            'accent' => self::DEFAULT_ACCENT,
            'on_primary' => self::DEFAULT_ON_PRIMARY,
        ];
    }

    public static function normalizeHex(?string $hex): ?string
    {
        if (! is_string($hex)) {
            return null;
        }

        $hex = strtoupper(trim($hex));

        return preg_match('/^#[0-9A-F]{6}$/', $hex) === 1 ? $hex : null;
    }

    private static function generateSlug(string $name, string $uuid): string
    {
        $base = Str::slug($name);
        $base = $base !== '' ? $base : 'bedrijf';

        return Str::limit($base, 59, '').'-'.$uuid;
    }
}
