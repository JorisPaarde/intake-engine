<?php

declare(strict_types=1);

namespace App\Domains\Intake\Models;

use App\Enums\ContributionAudience;
use App\Enums\ContributionTaskStatus;
use App\Enums\FollowUpItemType;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $intake_id
 * @property int $company_id
 * @property int|null $dossier_subject_id
 * @property int|null $intake_follow_up_item_id
 * @property ContributionAudience $audience
 * @property FollowUpItemType $type
 * @property string $prompt
 * @property string|null $decision_area_key
 * @property ContributionTaskStatus $status
 * @property int|null $requested_by
 * @property string|null $completed_by_type
 * @property int|null $completed_by_id
 * @property Carbon|null $completed_at
 * @property array<string, mixed>|null $meta
 * @property-read DossierSubject|null $subject
 * @property-read IntakeFollowUpItem|null $followUpItem
 */
class ContributionTask extends Model
{
    protected $fillable = [
        'intake_id',
        'company_id',
        'dossier_subject_id',
        'intake_follow_up_item_id',
        'audience',
        'type',
        'prompt',
        'decision_area_key',
        'status',
        'requested_by',
        'completed_by_type',
        'completed_by_id',
        'completed_at',
        'meta',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'intake_id' => 'integer',
            'company_id' => 'integer',
            'dossier_subject_id' => 'integer',
            'intake_follow_up_item_id' => 'integer',
            'audience' => ContributionAudience::class,
            'type' => FollowUpItemType::class,
            'status' => ContributionTaskStatus::class,
            'requested_by' => 'integer',
            'completed_by_id' => 'integer',
            'completed_at' => 'datetime',
            'meta' => 'array',
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

    /** @return BelongsTo<IntakeFollowUpItem, $this> */
    public function followUpItem(): BelongsTo
    {
        return $this->belongsTo(IntakeFollowUpItem::class, 'intake_follow_up_item_id');
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
