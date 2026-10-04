<?php

declare(strict_types=1);

namespace App\Domains\Intake\Models;

use App\Domains\AI\Support\PhotoContentAssessment;
use App\Enums\PhotoAssessmentStatus;
use App\Enums\PhotoUsabilityVerdict;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $intake_id
 * @property int|null $intake_follow_up_item_id
 * @property string $question_key
 * @property string|null $section_instance_key
 * @property string $disk
 * @property string $path
 * @property string|null $analysis_path
 * @property string|null $analysis_mime_type
 * @property int|null $analysis_size_bytes
 * @property string|null $analysis_checksum
 * @property string $original_filename
 * @property string $mime_type
 * @property int $size_bytes
 * @property int $sort_order
 * @property PhotoUsabilityVerdict|null $usability_verdict
 * @property array{persist_ms?: int, preprocess_ms?: int, network_upload_ms?: int, measured_at?: string, dossier_width?: int|null, dossier_height?: int|null, analysis_width?: int|null, analysis_height?: int|null, original_width?: int|null, original_height?: int|null, correlation_id?: string, variants_pending?: bool, variants_ready?: bool, variants_failed?: bool, variants_error?: string, variants_processed_at?: string, dossier_checksum?: string}|null $processing_timings
 * @property array<string, mixed>|null $content_assessment
 * @property PhotoAssessmentStatus|null $assessment_status
 * @property int|null $assessment_source_upload_id
 * @property int $assessment_attempts
 * @property Carbon|null $assessment_queued_at
 * @property-read IntakeFollowUpItem|null $followUpItem
 * @property-read IntakeUpload|null $assessmentSource
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
        'analysis_path',
        'analysis_mime_type',
        'analysis_size_bytes',
        'analysis_checksum',
        'original_filename',
        'mime_type',
        'size_bytes',
        'checksum',
        'sort_order',
        'usability_verdict',
        'processing_timings',
        'content_assessment',
        'assessment_status',
        'assessment_source_upload_id',
        'assessment_attempts',
        'assessment_queued_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'intake_id' => 'integer',
            'intake_follow_up_item_id' => 'integer',
            'size_bytes' => 'integer',
            'analysis_size_bytes' => 'integer',
            'sort_order' => 'integer',
            'usability_verdict' => PhotoUsabilityVerdict::class,
            'processing_timings' => 'array',
            'content_assessment' => 'array',
            'assessment_status' => PhotoAssessmentStatus::class,
            'assessment_source_upload_id' => 'integer',
            'assessment_attempts' => 'integer',
            'assessment_queued_at' => 'datetime',
        ];
    }

    public function contentAssessment(): ?PhotoContentAssessment
    {
        return PhotoContentAssessment::fromArray(
            is_array($this->content_assessment) ? $this->content_assessment : null,
        );
    }

    public function storeContentAssessment(
        PhotoContentAssessment $assessment,
        ?PhotoAssessmentStatus $pipelineStatus = null,
    ): void {
        $status = $pipelineStatus ?? (
            $assessment->status() === PhotoContentAssessment::STATUS_NOT_ASSESSED
                ? PhotoAssessmentStatus::NotAssessed
                : PhotoAssessmentStatus::Assessed
        );

        $this->forceFill([
            'content_assessment' => $assessment->toArray(),
            'assessment_status' => $status,
            'assessment_queued_at' => null,
        ])->save();
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

    /** @return BelongsTo<IntakeUpload, $this> */
    public function assessmentSource(): BelongsTo
    {
        return $this->belongsTo(self::class, 'assessment_source_upload_id');
    }
}
