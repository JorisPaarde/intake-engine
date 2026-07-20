<?php

declare(strict_types=1);

namespace App\Domains\Intake\Services;

use App\Domains\Intake\Models\Intake;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Support\Facades\Storage;

final class EmbedPrivateReportMedia
{
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

            $node->setAttribute(
                'src',
                'data:'.$upload->mime_type.';base64,'.base64_encode($disk->get($upload->path)),
            );
            $node->removeAttribute('data-intake-upload-id');
        }

        return $document->saveHTML() ?: $html;
    }
}
