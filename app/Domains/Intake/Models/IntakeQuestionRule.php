<?php

declare(strict_types=1);

namespace App\Domains\Intake\Models;

use App\Enums\RuleEffect;
use App\Enums\RuleOperator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $source_question_key
 * @property RuleOperator $operator
 * @property array<string, mixed>|null $value
 * @property RuleEffect $effect
 */
class IntakeQuestionRule extends Model
{
    protected $fillable = [
        'intake_question_id',
        'source_question_key',
        'operator',
        'value',
        'effect',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'operator' => RuleOperator::class,
            'effect' => RuleEffect::class,
            'value' => 'array',
        ];
    }

    /** @return BelongsTo<IntakeQuestion, $this> */
    public function question(): BelongsTo
    {
        return $this->belongsTo(IntakeQuestion::class, 'intake_question_id');
    }
}
