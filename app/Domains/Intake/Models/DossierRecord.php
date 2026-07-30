<?php

declare(strict_types=1);

namespace App\Domains\Intake\Models;

use App\Enums\DossierRecordKind;
use App\Enums\DossierRecordStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DossierRecord extends Model
{
    protected $fillable = [
        'intake_id',
        'company_id',
        'dossier_subject_id',
        'kind',
        'key',
        'value',
        'actor_type',
        'actor_id',
        'source_type',
        'source_id',
        'method',
        'confidence',
        'status',
        'observed_at',
        'superseded_by_id',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'intake_id' => 'integer',
            'company_id' => 'integer',
            'dossier_subject_id' => 'integer',
            'kind' => DossierRecordKind::class,
            'value' => 'array',
            'actor_id' => 'integer',
            'source_id' => 'integer',
            'confidence' => 'float',
            'status' => DossierRecordStatus::class,
            'observed_at' => 'datetime',
            'superseded_by_id' => 'integer',
        ];
    }

    /** @return BelongsTo<Intake, $this> */
    public function intake(): BelongsTo
    {
        return $this->belongsTo(Intake::class);
    }

    /** @return BelongsTo<DossierSubject, $this> */
    public function subject(): BelongsTo
    {
        return $this->belongsTo(DossierSubject::class, 'dossier_subject_id');
    }

    /** @return BelongsTo<DossierRecord, $this> */
    public function supersededBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'superseded_by_id');
    }

    /** @return HasMany<DossierEvidenceLink, $this> */
    public function evidenceLinks(): HasMany
    {
        return $this->hasMany(DossierEvidenceLink::class);
    }
}
