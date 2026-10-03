<?php

declare(strict_types=1);

namespace App\Domains\Intake\Services;

use App\Domains\Intake\Models\Intake;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Embeds private intake photos as data-URIs for PDF rendering only.
 * Originals on disk stay untouched; embeds are downscaled (max 1600px long edge,
 * JPEG ~quality 75) to keep rapport.pdf small.
 */
final class EmbedPrivateReportMedia
{
    public const PDF_MAX_LONG_EDGE = 1600;

    public const PDF_JPEG_QUALITY = 75;

    public function handle(Intake $intake, string $html): string
    {
        $intake->loadMissing('uploads');
        $uploads = $intake->uploads->keyBy('id');
        $document = new DOMDocument('1.0', 'UTF-8');

        $previous = libxml_use_internal_errors(true);

        try {
            if (! $document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET)) {
                return $html;
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        foreach (iterator_to_array($document->childNodes) as $node) {
            if ($node->nodeType === XML_PI_NODE) {
                $document->removeChild($node);
            }
        }

        $nodes = (new DOMXPath($document))->query('//img[@data-intake-upload-id]');

        if ($nodes === false) {
            return $html;
        }

        foreach ($nodes as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }

            $uploadId = filter_var($node->getAttribute('data-intake-upload-id'), FILTER_VALIDATE_INT);
            $upload = $uploadId === false ? null : $uploads->get($uploadId);

            if ($upload === null || ! str_starts_with($upload->mime_type, 'image/')) {
                continue;
            }

            $disk = Storage::disk($upload->disk);

            if (! $disk->exists($upload->path)) {
                continue;
            }

            $bytes = $disk->get($upload->path);
            if (! is_string($bytes) || $bytes === '') {
                continue;
            }

            [$mime, $embedded] = $this->downscaleForPdf($bytes, (string) $upload->mime_type);

            $node->setAttribute(
                'src',
                'data:'.$mime.';base64,'.base64_encode($embedded),
            );
            $node->removeAttribute('data-intake-upload-id');
        }

        return $document->saveHTML() ?: $html;
    }

    /**
     * @return array{0: string, 1: string} mime + binary
     */
    public function downscaleForPdf(string $bytes, string $mimeType): array
    {
        try {
            $image = @imagecreatefromstring($bytes);
            if ($image === false) {
                return [$mimeType, $bytes];
            }

            $width = imagesx($image);
            $height = imagesy($image);
            $longEdge = max($width, $height);
            if ($longEdge > self::PDF_MAX_LONG_EDGE) {
                $scale = self::PDF_MAX_LONG_EDGE / $longEdge;
                $targetW = max(1, (int) round($width * $scale));
                $targetH = max(1, (int) round($height * $scale));
                $resized = imagecreatetruecolor($targetW, $targetH);
                if ($resized === false) {
                    imagedestroy($image);

                    return [$mimeType, $bytes];
                }

                imagealphablending($resized, true);
                imagesavealpha($resized, true);
                imagecopyresampled($resized, $image, 0, 0, 0, 0, $targetW, $targetH, $width, $height);
                imagedestroy($image);
                $image = $resized;
            }

            ob_start();
            imagejpeg($image, null, self::PDF_JPEG_QUALITY);
            $jpeg = (string) ob_get_clean();
            imagedestroy($image);

            if ($jpeg === '') {
                return [$mimeType, $bytes];
            }

            return ['image/jpeg', $jpeg];
        } catch (Throwable) {
            return [$mimeType, $bytes];
        }
    }
}
