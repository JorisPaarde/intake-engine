<?php

declare(strict_types=1);

namespace App\Domains\AI\Services;

use App\Domains\Intake\Models\IntakeUpload;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Builds photo_refs for AI traces with width/height and resize target/variant size.
 */
final class AiTracePhotoRefBuilder
{
    /**
     * @param  'auto'|'low'|'high'|null  $detail
     * @return array<string, mixed>
     */
    public function fromUpload(IntakeUpload $upload, ?string $category = null, ?string $detail = null): array
    {
        $dossierDims = $this->dimensions($upload, analysis: false);
        $analysisDims = $this->dimensions($upload, analysis: true);
        $resolvedDetail = $this->resolveDetail($detail);

        return [
            'upload_id' => $upload->id,
            'question_key' => $upload->question_key,
            'section_instance_key' => $upload->section_instance_key,
            'category' => $category,
            'detail' => $resolvedDetail,
            'mime_type' => $upload->analysis_mime_type ?? $upload->mime_type,
            'size_bytes' => $upload->size_bytes,
            'analysis_size_bytes' => $upload->analysis_size_bytes,
            'checksum' => $upload->analysis_checksum ?? $upload->checksum,
            'sort_order' => $upload->sort_order,
            'path_ref' => 'intake_upload:'.$upload->id,
            'width' => $analysisDims['width'] ?? $dossierDims['width'],
            'height' => $analysisDims['height'] ?? $dossierDims['height'],
            'dossier' => [
                'width' => $dossierDims['width'],
                'height' => $dossierDims['height'],
                'size_bytes' => $upload->size_bytes,
                'max_long_edge' => (int) config('intake.uploads.dossier.max_long_edge', 2048),
            ],
            'analysis_variant' => [
                'width' => $analysisDims['width'],
                'height' => $analysisDims['height'],
                'size_bytes' => $upload->analysis_size_bytes,
                'max_long_edge' => (int) config('intake.uploads.analysis.max_long_edge', 1536),
            ],
        ];
    }

    /**
     * @return 'auto'|'low'|'high'
     */
    private function resolveDetail(?string $detail): string
    {
        return match ($detail) {
            'low', 'high', 'auto' => $detail,
            default => 'auto',
        };
    }

    /**
     * @return array{width: int|null, height: int|null}
     */
    private function dimensions(IntakeUpload $upload, bool $analysis): array
    {
        $timings = $upload->processing_timings ?? [];
        if ($analysis) {
            $width = $this->intOrNull($timings['analysis_width'] ?? null);
            $height = $this->intOrNull($timings['analysis_height'] ?? null);
        } else {
            $width = $this->intOrNull($timings['dossier_width'] ?? null);
            $height = $this->intOrNull($timings['dossier_height'] ?? null);
        }

        if ($width !== null && $height !== null) {
            return ['width' => $width, 'height' => $height];
        }

        $disk = (string) $upload->disk;
        if ($disk !== 'local' && ! str_starts_with($disk, 'local')) {
            return ['width' => $width, 'height' => $height];
        }

        $path = $analysis ? ($upload->analysis_path ?? $upload->path) : $upload->path;

        try {
            $absolute = Storage::disk($disk)->path($path);
            $info = @getimagesize($absolute);
            if (is_array($info)) {
                return [
                    'width' => $width ?? (int) $info[0],
                    'height' => $height ?? (int) $info[1],
                ];
            }
        } catch (Throwable) {
            // Soft-fail: dimensions are diagnostic only.
        }

        return ['width' => $width, 'height' => $height];
    }

    private function intOrNull(mixed $value): ?int
    {
        if (! is_int($value) && ! is_numeric($value)) {
            return null;
        }

        return max(0, (int) $value);
    }
}
