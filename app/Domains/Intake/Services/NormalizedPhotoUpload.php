<?php

declare(strict_types=1);

namespace App\Domains\Intake\Services;

final readonly class NormalizedPhotoUpload
{
    /**
     * @param  list<string>  $cleanupPaths
     */
    public function __construct(
        public string $absolutePath,
        public string $mime,
        public string $extension,
        public int $sizeBytes,
        public string $checksum,
        public string $originalFilename,
        public array $cleanupPaths = [],
    ) {}
}
