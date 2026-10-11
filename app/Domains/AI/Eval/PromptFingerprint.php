<?php

declare(strict_types=1);

namespace App\Domains\AI\Eval;

use App\Domains\AI\Services\PromptVersionRepository;
use Illuminate\Support\Facades\File;
use Throwable;

/**
 * Vingerafdruk van promptbestanden voor eval-geschiedenis (alleen meten).
 */
final class PromptFingerprint
{
    public function __construct(
        private readonly PromptVersionRepository $promptVersions,
    ) {}

    /**
     * @param  list<string>  $promptNames
     * @return array{
     *     combined_hash: string,
     *     date: string,
     *     prompts: list<array{
     *         name: string,
     *         version: string|null,
     *         prompt_path: string,
     *         meta_path: string,
     *         prompt_sha256: string|null,
     *         meta_sha256: string|null,
     *         content_sha256: string|null
     *     }>
     * }
     */
    public function capture(array $promptNames): array
    {
        $prompts = [];
        $hashParts = [];

        foreach ($promptNames as $name) {
            $base = app_path('Domains/AI/Prompts/'.$name);
            $promptPath = $base.'/prompt.md';
            $metaPath = $base.'/meta.php';
            $promptSha = File::isFile($promptPath) ? hash_file('sha256', $promptPath) : null;
            $metaSha = File::isFile($metaPath) ? hash_file('sha256', $metaPath) : null;
            $version = null;
            try {
                $version = $this->promptVersions->version($name);
            } catch (Throwable) {
                $version = null;
            }

            $contentSha = hash('sha256', ($promptSha ?? '').'|'.($metaSha ?? '').'|'.($version ?? ''));
            $hashParts[] = $name.':'.$contentSha;

            $prompts[] = [
                'name' => $name,
                'version' => $version,
                'prompt_path' => 'app/Domains/AI/Prompts/'.$name.'/prompt.md',
                'meta_path' => 'app/Domains/AI/Prompts/'.$name.'/meta.php',
                'prompt_sha256' => $promptSha,
                'meta_sha256' => $metaSha,
                'content_sha256' => $contentSha,
            ];
        }

        sort($hashParts);

        return [
            'combined_hash' => substr(hash('sha256', implode(';', $hashParts)), 0, 12),
            'date' => now()->format('Y-m-d'),
            'prompts' => $prompts,
        ];
    }
}
