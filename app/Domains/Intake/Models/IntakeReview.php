<?php

declare(strict_types=1);

namespace App\Domains\Intake\Models;

use App\Enums\ReviewDecision;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IntakeReview extends Model
{
    protected $fillable = [
        'intake_id',
        'reviewer_id',
        'decision',
        'site_visit_needed',
        'enough_information',
        'summary',
        'reviewed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'decision' => ReviewDecision::class,
            'site_visit_needed' => 'boolean',
            'enough_information' => 'boolean',
            'reviewed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Intake, $this> */
    public function intake(): BelongsTo
    {
        return $this->belongsTo(Intake::class);
    }

    /** @return BelongsTo<User, $this> */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }
}
