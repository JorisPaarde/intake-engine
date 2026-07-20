<?php

declare(strict_types=1);

namespace App\Domains\Intake\Models;

use App\Enums\PhotoUsabilityVerdict;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property int $intake_id
 * @property int|null $intake_follow_up_item_id
 * @property string $question_key
 * @property string|null $section_instance_key
 * @property string $disk
 * @property string $path
 * @property string $original_filename
 * @property string $mime_type
 * @property int $size_bytes
 * @property int $sort_order
 * @property PhotoUsabilityVerdict|null $usability_verdict
 * @property-read IntakeFollowUpItem|null $followUpItem
 */
class IntakeUpload extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'intake_id',
        'question_key',
        'section_instance_key',
        'intake_follow_up_item_id',
        'disk',
        'path',
        'original_filename',
        'mime_type',
        'size_bytes',
        'checksum',
        'sort_order',
        'usability_verdict',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'sort_order' => 'integer',
            'usability_verdict' => PhotoUsabilityVerdict::class,
        ];
    }

    /** @return BelongsTo<Intake, $this> */
    public function intake(): BelongsTo
    {
        return $this->belongsTo(Intake::class);
    }

    /** @return BelongsTo<IntakeFollowUpItem, $this> */
    public function followUpItem(): BelongsTo
    {
        return $this->belongsTo(IntakeFollowUpItem::class, 'intake_follow_up_item_id');
    }
}
