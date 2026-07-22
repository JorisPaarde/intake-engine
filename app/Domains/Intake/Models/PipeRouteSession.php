<?php

declare(strict_types=1);

namespace App\Domains\Intake\Models;

use App\Enums\PipeRouteStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Een begeleide leidingroute-sessie per intake: verzamelt beoordeelde routefoto's
 * (segmenten) en houdt de samengevatte route + onzekerheden bij tot de installateur
 * hem goedkeurt. Draait los van de bestaande pipe_route-vragen (parallel, ADR-0001).
 *
 * @property PipeRouteStatus $status
 * @property float|null $confidence
 * @property array<string, mixed>|null $proposed_route
 * @property array<string, mixed>|null $alternative_route
 * @property list<string>|null $uncertainties
 * @property list<string>|null $missing_checks
 * @property string|null $next_photo_instruction
 * @property Carbon|null $approved_at
 */
class PipeRouteSession extends Model
{
    protected $fillable = [
        'intake_id',
        'status',
        'confidence',
        'proposed_route',
        'alternative_route',
        'uncertainties',
        'missing_checks',
        'next_photo_instruction',
        'approved_by',
        'approved_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => PipeRouteStatus::class,
            'confidence' => 'float',
            'proposed_route' => 'array',
            'alternative_route' => 'array',
            'uncertainties' => 'array',
            'missing_checks' => 'array',
            'approved_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Intake, $this> */
    public function intake(): BelongsTo
    {
        return $this->belongsTo(Intake::class);
    }

    /** @return BelongsTo<User, $this> */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** @return HasMany<PipeRouteSegment, $this> */
    public function segments(): HasMany
    {
        return $this->hasMany(PipeRouteSegment::class)->orderBy('sequence');
    }
}
