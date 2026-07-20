<?php

declare(strict_types=1);

namespace App\Domains\Intake\Models;

use App\Enums\FollowUpItemType;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $intake_follow_up_round_id
 * @property FollowUpItemType $type
 * @property string $prompt
 * @property string|null $response_text
 * @property Carbon|null $answered_at
 * @property-read IntakeFollowUpRound $round
 * @property-read Collection<int, IntakeUpload> $uploads
 */
class IntakeFollowUpItem extends Model
{
    protected $fillable = [
        'intake_follow_up_round_id',
        'type',
        'prompt',
        'response_text',
        'answered_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => FollowUpItemType::class,
            'answered_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<IntakeFollowUpRound, $this> */
    public function round(): BelongsTo
    {
        return $this->belongsTo(IntakeFollowUpRound::class, 'intake_follow_up_round_id');
    }

    /** @return HasMany<IntakeUpload, $this> */
    public function uploads(): HasMany
    {
        return $this->hasMany(IntakeUpload::class, 'intake_follow_up_item_id')->orderBy('sort_order');
    }
}
