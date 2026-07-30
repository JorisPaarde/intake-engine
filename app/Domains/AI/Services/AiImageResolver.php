<?php

declare(strict_types=1);

namespace App\Domains\AI\Services;

use App\Domains\AI\DTOs\AiImageInput;
use App\Domains\Intake\Models\IntakeUpload;
use Illuminate\Support\Facades\Storage;

/**
 * Single gateway for vision bytes. New uploads use the smaller analysis variant;
 * historical rows safely fall back to their dossier image.
 */
final class AiImageResolver
{
    public function input(IntakeUpload $upload): AiImageInput
    {
        [$path, $mime] = $this->location($upload);

        return new AiImageInput(
            mimeType: $mime,
            binary: Storage::disk($upload->disk)->get($path),
        );
    }

    /** @return array{checksum: string|null, mime_type: string, variant: string} */
    public function identity(IntakeUpload $upload): array
    {
        $usesAnalysis = $this->hasAnalysisVariant($upload);

        return [
            'checksum' => $usesAnalysis ? $upload->analysis_checksum : $upload->checksum,
            'mime_type' => $usesAnalysis
                ? (string) $upload->analysis_mime_type
                : $upload->mime_type,
            'variant' => $usesAnalysis ? 'analysis' : 'dossier_fallback',
        ];
    }

    /** @return array{0: string, 1: string} */
    private function location(IntakeUpload $upload): array
    {
        if ($this->hasAnalysisVariant($upload)) {
            return [(string) $upload->analysis_path, (string) $upload->analysis_mime_type];
        }

        if (! in_array($upload->mime_type, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            throw new \RuntimeException('Opgeslagen foto heeft geen ondersteund formaat voor beeldanalyse.');
        }

        return [$upload->path, $upload->mime_type];
    }

    private function hasAnalysisVariant(IntakeUpload $upload): bool
    {
        return is_string($upload->analysis_path)
            && $upload->analysis_path !== ''
            && $upload->analysis_mime_type === 'image/jpeg'
            && Storage::disk($upload->disk)->exists($upload->analysis_path);
    }
}
