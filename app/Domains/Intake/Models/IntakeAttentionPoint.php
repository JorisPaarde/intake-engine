<?php

declare(strict_types=1);

namespace App\Domains\Intake\Models;

use App\Enums\AttentionPointSource;
use App\Enums\AttentionPointStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $intake_id
 * @property AttentionPointSource $source
 * @property string|null $code
 * @property string $label
 * @property AttentionPointStatus|null $status
 * @property bool $is_resolved
 */
class IntakeAttentionPoint extends Model
{
    protected $fillable = [
        'intake_id',
        'source',
        'code',
        'label',
        'status',
        'is_resolved',
        'resolved_at',
        'resolved_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'source' => AttentionPointSource::class,
            'status' => AttentionPointStatus::class,
            'is_resolved' => 'boolean',
            'resolved_at' => 'datetime',
        ];
    }

    /**
     * Points that count as real: system/reviewer (no status) or an accepted AI proposal.
     * Excludes still-`proposed` and `dismissed` AI points (BL-007).
     *
     * @param  Builder<IntakeAttentionPoint>  $query
     */
    public function scopeAuthoritative(Builder $query): void
    {
        $query->where(function (Builder $q): void {
            $q->whereNull('status')->orWhere('status', AttentionPointStatus::Accepted->value);
        });
    }

    /**
     * AI proposals still awaiting the installer's accept/dismiss decision.
     *
     * @param  Builder<IntakeAttentionPoint>  $query
     */
    public function scopeAiProposed(Builder $query): void
    {
        $query->where('source', AttentionPointSource::Ai->value)
            ->where('status', AttentionPointStatus::Proposed->value);
    }

    /** @return BelongsTo<Intake, $this> */
    public function intake(): BelongsTo
    {
        return $this->belongsTo(Intake::class);
    }

    /** @return BelongsTo<User, $this> */
    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
