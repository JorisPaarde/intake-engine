<?php

declare(strict_types=1);

namespace App\Domains\Intake\Models;

use App\Enums\AircoOptionStatus;
use App\Enums\AircoPlacementType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @property int $id
 * @property int $intake_id
 * @property int $company_id
 * @property int|null $airco_room_id
 * @property int $dossier_subject_id
 * @property AircoPlacementType $type
 * @property string $label
 * @property string|null $description
 * @property array<string, mixed>|null $location_data
 * @property AircoOptionStatus $status
 * @property string $source_type
 * @property int|null $source_id
 * @property float|null $confidence
 * @property array<int, string>|null $cost_risks
 * @property-read AircoRoom|null $room
 * @property-read DossierSubject $subject
 */
class AircoPlacementOption extends Model
{
    protected $fillable = [
        'intake_id',
        'company_id',
        'airco_room_id',
        'dossier_subject_id',
        'type',
        'label',
        'description',
        'location_data',
        'status',
        'source_type',
        'source_id',
        'confidence',
        'cost_risks',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'intake_id' => 'integer',
            'company_id' => 'integer',
            'airco_room_id' => 'integer',
            'dossier_subject_id' => 'integer',
            'type' => AircoPlacementType::class,
            'location_data' => 'array',
            'status' => AircoOptionStatus::class,
            'source_id' => 'integer',
            'confidence' => 'float',
            'cost_risks' => 'array',
        ];
    }

    /** @return BelongsTo<Intake, $this> */
    public function intake(): BelongsTo
    {
        return $this->belongsTo(Intake::class);
    }

    /** @return BelongsTo<AircoRoom, $this> */
    public function room(): BelongsTo
    {
        return $this->belongsTo(AircoRoom::class, 'airco_room_id');
    }

    /** @return BelongsTo<DossierSubject, $this> */
    public function subject(): BelongsTo
    {
        return $this->belongsTo(DossierSubject::class, 'dossier_subject_id');
    }

    /** @return BelongsToMany<AircoInstallationOption, $this> */
    public function installationOptions(): BelongsToMany
    {
        return $this->belongsToMany(
            AircoInstallationOption::class,
            'airco_installation_option_placements',
        )->withPivot(['role', 'sort_order'])->withTimestamps();
    }
}
