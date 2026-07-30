<?php

declare(strict_types=1);

namespace App\Domains\Intake\Models;

use App\Enums\AircoConfigurationType;
use App\Enums\AircoOptionStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $intake_id
 * @property int $company_id
 * @property string $label
 * @property AircoConfigurationType $configuration_type
 * @property int|null $rank
 * @property AircoOptionStatus $status
 * @property string|null $summary
 * @property string|null $cost_impact
 * @property string $source_type
 * @property int|null $source_id
 * @property float|null $confidence
 * @property int|null $created_by
 * @property Carbon|null $selected_at
 */
class AircoInstallationOption extends Model
{
    protected $fillable = [
        'intake_id',
        'company_id',
        'label',
        'configuration_type',
        'rank',
        'status',
        'summary',
        'cost_impact',
        'source_type',
        'source_id',
        'confidence',
        'created_by',
        'selected_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'intake_id' => 'integer',
            'company_id' => 'integer',
            'configuration_type' => AircoConfigurationType::class,
            'rank' => 'integer',
            'status' => AircoOptionStatus::class,
            'source_id' => 'integer',
            'confidence' => 'float',
            'created_by' => 'integer',
            'selected_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Intake, $this> */
    public function intake(): BelongsTo
    {
        return $this->belongsTo(Intake::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsToMany<AircoPlacementOption, $this> */
    public function placements(): BelongsToMany
    {
        return $this->belongsToMany(
            AircoPlacementOption::class,
            'airco_installation_option_placements',
        )->withPivot(['role', 'sort_order'])->withTimestamps();
    }

    /** @return HasMany<AircoConnection, $this> */
    public function connections(): HasMany
    {
        return $this->hasMany(AircoConnection::class);
    }
}
