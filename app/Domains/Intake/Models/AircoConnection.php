<?php

declare(strict_types=1);

namespace App\Domains\Intake\Models;

use App\Enums\AircoConnectionStatus;
use App\Enums\AircoConnectionType;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class AircoConnection extends Model
{
    protected $fillable = [
        'intake_id',
        'company_id',
        'airco_installation_option_id',
        'from_placement_id',
        'to_placement_id',
        'dossier_subject_id',
        'type',
        'label',
        'status',
        'length_class',
        'segments',
        'obstacles',
        'uncertainties',
        'cost_impact',
        'confidence',
        'source_type',
        'source_id',
        'safety_check_required',
        'approved_by',
        'approved_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'intake_id' => 'integer',
            'company_id' => 'integer',
            'airco_installation_option_id' => 'integer',
            'from_placement_id' => 'integer',
            'to_placement_id' => 'integer',
            'dossier_subject_id' => 'integer',
            'type' => AircoConnectionType::class,
            'status' => AircoConnectionStatus::class,
            'segments' => 'array',
            'obstacles' => 'array',
            'uncertainties' => 'array',
            'confidence' => 'float',
            'source_id' => 'integer',
            'safety_check_required' => 'boolean',
            'approved_by' => 'integer',
            'approved_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<AircoInstallationOption, $this> */
    public function installationOption(): BelongsTo
    {
        return $this->belongsTo(AircoInstallationOption::class);
    }

    /** @return BelongsTo<AircoPlacementOption, $this> */
    public function fromPlacement(): BelongsTo
    {
        return $this->belongsTo(AircoPlacementOption::class, 'from_placement_id');
    }

    /** @return BelongsTo<AircoPlacementOption, $this> */
    public function toPlacement(): BelongsTo
    {
        return $this->belongsTo(AircoPlacementOption::class, 'to_placement_id');
    }

    /** @return BelongsTo<DossierSubject, $this> */
    public function subject(): BelongsTo
    {
        return $this->belongsTo(DossierSubject::class, 'dossier_subject_id');
    }

    /** @return BelongsTo<User, $this> */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** @return HasOne<PipeRouteSession, $this> */
    public function routeSession(): HasOne
    {
        return $this->hasOne(PipeRouteSession::class);
    }
}
