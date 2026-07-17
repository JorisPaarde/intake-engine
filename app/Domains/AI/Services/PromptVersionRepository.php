<?php

declare(strict_types=1);

namespace App\Domains\AI\Services;

use Illuminate\Support\Facades\File;
use RuntimeException;

final class PromptVersionRepository
{
    public function version(string $name): string
    {
        $metaPath = $this->basePath($name).'/meta.php';

        if (! File::isFile($metaPath)) {
            throw new RuntimeException("Prompt meta ontbreekt voor [{$name}].");
        }

        /** @var array{version: string} $meta */
        $meta = require $metaPath;

        return $meta['version'];
    }

    public function body(string $name): string
    {
        $path = $this->basePath($name).'/prompt.md';

        if (! File::isFile($path)) {
            throw new RuntimeException("Promptbestand ontbreekt voor [{$name}].");
        }

        return trim((string) File::get($path));
    }

    private function basePath(string $name): string
    {
        return app_path('Domains/AI/Prompts/'.$name);
    }
}
