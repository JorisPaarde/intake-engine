<?php

declare(strict_types=1);

namespace App\Domains\AI\Eval;

use App\Domains\AI\Services\PromptVersionRepository;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use RuntimeException;

/**
 * Resolve prompt body + version for eval, including held-out proposals
 * (e.g. room_assessment/proposed-v9) without making them live.
 *
 * Selectors:
 * - null / '' / 'live' → live PromptVersionRepository path
 * - 'v9' → live path (must match *-v9 in meta)
 * - 'proposed-v9' → {prompt}/proposed-v9/ (alias / held-out)
 */
final class EvalPromptVersionResolver
{
    public function __construct(
        private readonly PromptVersionRepository $promptVersions,
    ) {}

    /**
     * @return array{body: string, version: string, source: 'live'|'proposed', selector: string}
     */
    public function resolve(string $promptName, ?string $selector = null): array
    {
        $normalized = $this->normalizeSelector($selector);

        if ($normalized === 'live') {
            $version = $this->promptVersions->version($promptName);
            $body = $this->promptVersions->body($promptName);

            return [
                'body' => $body,
                'version' => $version,
                'source' => 'live',
                'selector' => $normalized,
            ];
        }

        if (preg_match('/^v(\d+)$/', $normalized, $m) === 1) {
            $version = $this->promptVersions->version($promptName);
            $expectedSuffix = '-v'.$m[1];
            if (! str_ends_with($version, $expectedSuffix)) {
                throw new InvalidArgumentException(
                    "Prompt [{$promptName}] live version is [{$version}], niet *{$expectedSuffix} (selector={$normalized})."
                );
            }

            return [
                'body' => $this->promptVersions->body($promptName),
                'version' => $version,
                'source' => 'live',
                'selector' => $normalized,
            ];
        }

        if (str_starts_with($normalized, 'proposed-')) {
            $dir = app_path('Domains/AI/Prompts/'.$promptName.'/'.$normalized);
            $metaPath = $dir.'/meta.php';
            $bodyPath = $dir.'/prompt.md';

            if (! File::isFile($metaPath) || ! File::isFile($bodyPath)) {
                throw new InvalidArgumentException(
                    "Held-out prompt ontbreekt: {$dir} (meta.php + prompt.md vereist)."
                );
            }

            /** @var array{version?: mixed} $meta */
            $meta = require $metaPath;
            $version = is_string($meta['version'] ?? null) ? $meta['version'] : null;
            if ($version === null || $version === '') {
                throw new RuntimeException("Prompt meta zonder version in [{$metaPath}].");
            }

            return [
                'body' => trim((string) File::get($bodyPath)),
                'version' => $version,
                'source' => 'proposed',
                'selector' => $normalized,
            ];
        }

        throw new InvalidArgumentException(
            "Onbekende --prompt-version [{$normalized}]. Gebruik v9, proposed-v9, live, of leeg."
        );
    }

    private function normalizeSelector(?string $selector): string
    {
        if ($selector === null) {
            return 'live';
        }
        $trimmed = strtolower(trim($selector));

        return $trimmed === '' ? 'live' : $trimmed;
    }
}
