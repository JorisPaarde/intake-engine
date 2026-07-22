<?php

declare(strict_types=1);

namespace App\Domains\Intake\Models;

use App\Enums\FollowUpRoundStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $intake_id
 * @property int $requested_by
 * @property int $round_number
 * @property FollowUpRoundStatus $status
 * @property Carbon $sent_at
 * @property Carbon|null $completed_at
 * @property-read Intake $intake
 * @property-read User $requester
 * @property-read Collection<int, IntakeFollowUpItem> $items
 */
class IntakeFollowUpRound extends Model
{
    protected $fillable = [
        'intake_id',
        'requested_by',
        'round_number',
        'status',
        'sent_at',
        'completed_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'intake_id' => 'integer',
            'round_number' => 'integer',
            'status' => FollowUpRoundStatus::class,
            'sent_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Intake, $this> */
    public function intake(): BelongsTo
    {
        return $this->belongsTo(Intake::class);
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /** @return HasMany<IntakeFollowUpItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(IntakeFollowUpItem::class)->orderBy('id');
    }
}
