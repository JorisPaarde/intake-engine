<?php

declare(strict_types=1);

namespace App\Domains\Intake\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IntakeQuestionOption extends Model
{
    protected $fillable = [
        'intake_question_id',
        'value',
        'label',
        'sort_order',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }

    /** @return BelongsTo<IntakeQuestion, $this> */
    public function question(): BelongsTo
    {
        return $this->belongsTo(IntakeQuestion::class, 'intake_question_id');
    }
}
