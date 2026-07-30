<?php

declare(strict_types=1);

namespace App\Domains\Intake\Services;

final readonly class NormalizedPhotoUpload
{
    /**
     * @param  list<string>  $cleanupPaths
     */
    public function __construct(
        public string $dossierAbsolutePath,
        public string $dossierMime,
        public string $dossierExtension,
        public int $dossierSizeBytes,
        public string $dossierChecksum,
        public string $analysisAbsolutePath,
        public string $analysisMime,
        public string $analysisExtension,
        public int $analysisSizeBytes,
        public string $analysisChecksum,
        public string $originalFilename,
        public array $cleanupPaths = [],
    ) {}
}
