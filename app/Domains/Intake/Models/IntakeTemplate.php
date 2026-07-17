<?php

declare(strict_types=1);

namespace App\Domains\Intake\Models;

use App\Enums\TemplateVersionStatus;
use Database\Factories\IntakeTemplateFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class IntakeTemplate extends Model
{
    /** @use HasFactory<IntakeTemplateFactory> */
    use HasFactory;

    protected $fillable = [
        'key',
        'name',
        'description',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    protected static function newFactory(): IntakeTemplateFactory
    {
        return IntakeTemplateFactory::new();
    }

    /** @return HasMany<IntakeTemplateVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(IntakeTemplateVersion::class);
    }

    public function latestPublishedVersion(): ?IntakeTemplateVersion
    {
        return $this->versions()
            ->where('status', TemplateVersionStatus::Published)
            ->orderByDesc('version')
            ->first();
    }
}
