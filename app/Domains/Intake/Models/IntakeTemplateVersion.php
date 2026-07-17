<?php

declare(strict_types=1);

namespace App\Domains\Intake\Models;

use App\Enums\TemplateVersionStatus;
use Database\Factories\IntakeTemplateVersionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property TemplateVersionStatus $status
 * @property Carbon|null $published_at
 * @property int $version
 */
class IntakeTemplateVersion extends Model
{
    /** @use HasFactory<IntakeTemplateVersionFactory> */
    use HasFactory;

    protected $fillable = [
        'intake_template_id',
        'version',
        'status',
        'published_at',
        'change_notes',
    ];

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'status' => TemplateVersionStatus::class,
            'published_at' => 'datetime',
            'version' => 'integer',
        ];
    }

    protected static function newFactory(): IntakeTemplateVersionFactory
    {
        return IntakeTemplateVersionFactory::new();
    }

    /** @return BelongsTo<IntakeTemplate, $this> */
    public function template(): BelongsTo
    {
        return $this->belongsTo(IntakeTemplate::class, 'intake_template_id');
    }

    /** @return HasMany<IntakeSection, $this> */
    public function sections(): HasMany
    {
        return $this->hasMany(IntakeSection::class)->orderBy('sort_order');
    }

    /** @return HasMany<Intake, $this> */
    public function intakes(): HasMany
    {
        return $this->hasMany(Intake::class);
    }
}
