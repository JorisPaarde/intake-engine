<?php

declare(strict_types=1);

namespace App\Domains\Intake\Models;

use App\Enums\QuestionType;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $key
 * @property QuestionType $type
 * @property bool $is_required
 * @property array<string, mixed>|null $validation_rules
 * @property array<string, mixed>|null $meta
 * @property EloquentCollection<int, IntakeQuestionRule> $rules
 */
class IntakeQuestion extends Model
{
    protected $fillable = [
        'intake_section_id',
        'key',
        'type',
        'label',
        'help_text',
        'photo_instructions',
        'is_required',
        'sort_order',
        'validation_rules',
        'meta',
    ];

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'type' => QuestionType::class,
            'is_required' => 'boolean',
            'sort_order' => 'integer',
            'validation_rules' => 'array',
            'meta' => 'array',
        ];
    }

    /** @return BelongsTo<IntakeSection, $this> */
    public function section(): BelongsTo
    {
        return $this->belongsTo(IntakeSection::class, 'intake_section_id');
    }

    /** @return HasMany<IntakeQuestionOption, $this> */
    public function options(): HasMany
    {
        return $this->hasMany(IntakeQuestionOption::class)->orderBy('sort_order');
    }

    /** @return HasMany<IntakeQuestionRule, $this> */
    public function rules(): HasMany
    {
        return $this->hasMany(IntakeQuestionRule::class);
    }
}
