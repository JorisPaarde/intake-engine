<?php

declare(strict_types=1);

namespace App\Domains\Intake\Models;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $key
 * @property bool $is_repeatable
 * @property string|null $repeat_count_question_key
 * @property EloquentCollection<int, IntakeQuestion> $questions
 */
class IntakeSection extends Model
{
    protected $fillable = [
        'intake_template_version_id',
        'key',
        'title',
        'description',
        'sort_order',
        'is_repeatable',
        'repeat_count_question_key',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_repeatable' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /** @return BelongsTo<IntakeTemplateVersion, $this> */
    public function version(): BelongsTo
    {
        return $this->belongsTo(IntakeTemplateVersion::class, 'intake_template_version_id');
    }

    /** @return HasMany<IntakeQuestion, $this> */
    public function questions(): HasMany
    {
        return $this->hasMany(IntakeQuestion::class)->orderBy('sort_order');
    }
}
