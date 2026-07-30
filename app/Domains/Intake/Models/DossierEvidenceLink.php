<?php

declare(strict_types=1);

namespace App\Domains\Intake\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DossierEvidenceLink extends Model
{
    protected $fillable = [
        'intake_id',
        'company_id',
        'dossier_subject_id',
        'dossier_record_id',
        'evidence_type',
        'evidence_id',
        'relationship',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'intake_id' => 'integer',
            'company_id' => 'integer',
            'dossier_subject_id' => 'integer',
            'dossier_record_id' => 'integer',
            'evidence_id' => 'integer',
        ];
    }

    /** @return BelongsTo<DossierSubject, $this> */
    public function subject(): BelongsTo
    {
        return $this->belongsTo(DossierSubject::class, 'dossier_subject_id');
    }

    /** @return BelongsTo<DossierRecord, $this> */
    public function record(): BelongsTo
    {
        return $this->belongsTo(DossierRecord::class, 'dossier_record_id');
    }
}
