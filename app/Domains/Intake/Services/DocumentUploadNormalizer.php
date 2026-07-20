<?php

declare(strict_types=1);

namespace App\Domains\Intake\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class DocumentUploadNormalizer
{
    public function __construct(
        private readonly UploadMimeDetector $mimeDetector,
    ) {}

    public function normalize(UploadedFile $file): NormalizedDocumentUpload
    {
        $path = $file->getRealPath() ?: $file->getPathname();
        $mime = $this->mimeDetector->detect($file);

        if (! in_array($mime, $this->acceptedMimes(), true) || ! $this->hasPdfSignature($path)) {
            throw ValidationException::withMessages([
                'upload' => 'Alleen een geldig PDF-document is toegestaan.',
            ]);
        }

        $sizeBytes = filesize($path);

        if ($sizeBytes === false) {
            throw ValidationException::withMessages([
                'upload' => 'Upload mislukt. Probeer het opnieuw.',
            ]);
        }

        $maxKilobytes = (int) config('intake.uploads.max_kilobytes', 5120);

        if ($sizeBytes > $maxKilobytes * 1024) {
            throw ValidationException::withMessages([
                'upload' => 'Dit document is te groot. Maximaal '.number_format($maxKilobytes / 1024, 0, ',', '.').' MB.',
            ]);
        }

        $checksum = hash_file('sha256', $path);

        if ($checksum === false) {
            throw ValidationException::withMessages([
                'upload' => 'Upload mislukt. Probeer het opnieuw.',
            ]);
        }

        return new NormalizedDocumentUpload(
            absolutePath: $path,
            mime: 'application/pdf',
            extension: 'pdf',
            sizeBytes: $sizeBytes,
            checksum: $checksum,
            originalFilename: Str::limit((string) $file->getClientOriginalName(), 240, ''),
        );
    }

    /** @return list<string> */
    private function acceptedMimes(): array
    {
        return array_values(array_filter(
            config('intake.uploads.document_mimes', ['application/pdf']),
            'is_string',
        ));
    }

    private function hasPdfSignature(string $path): bool
    {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return false;
        }

        try {
            return fread($handle, 5) === '%PDF-';
        } finally {
            fclose($handle);
        }
    }
}
