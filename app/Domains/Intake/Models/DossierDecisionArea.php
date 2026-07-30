<?php

declare(strict_types=1);

namespace App\Domains\Intake\Models;

use App\Enums\DecisionAreaStatus;
use App\Enums\DossierNextAction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DossierDecisionArea extends Model
{
    protected $fillable = [
        'intake_id',
        'company_id',
        'key',
        'label',
        'status',
        'next_action',
        'blocker',
        'blocking_subject_id',
        'cost_risks',
        'evidence_summary',
        'assessed_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'intake_id' => 'integer',
            'company_id' => 'integer',
            'status' => DecisionAreaStatus::class,
            'next_action' => DossierNextAction::class,
            'blocking_subject_id' => 'integer',
            'cost_risks' => 'array',
            'evidence_summary' => 'array',
            'assessed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Intake, $this> */
    public function intake(): BelongsTo
    {
        return $this->belongsTo(Intake::class);
    }

    /** @return BelongsTo<DossierSubject, $this> */
    public function blockingSubject(): BelongsTo
    {
        return $this->belongsTo(DossierSubject::class, 'blocking_subject_id');
    }
}
