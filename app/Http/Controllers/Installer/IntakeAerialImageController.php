<?php

declare(strict_types=1);

namespace App\Http\Controllers\Installer;

use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeExternalFact;
use App\Domains\Intake\Services\PdokAerialImageService;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves the stored aerial JPEG via an authorized, cacheable route so the
 * workspace/show HTML does not embed a multi-hundred-KB data URI.
 */
final class IntakeAerialImageController extends Controller
{
    public function show(Intake $intake): StreamedResponse
    {
        $this->authorize('view', $intake);

        $fact = $this->selectAerialFact($intake);

        if (! $fact instanceof IntakeExternalFact) {
            abort(404);
        }

        $disk = $fact->value['media_disk'] ?? null;
        $path = $fact->value['media_path'] ?? null;
        $mimeType = $fact->value['mime_type'] ?? null;

        if (! is_string($disk) || $disk === ''
            || ! is_string($path) || $path === ''
            || $mimeType !== 'image/jpeg') {
            abort(404);
        }

        $storage = Storage::disk($disk);

        if (! $storage->exists($path)) {
            abort(404);
        }

        return $storage->response($path, 'luchtfoto.jpg', [
            'Content-Type' => 'image/jpeg',
            // Short private cache: gallery feel without leaving the image after logout
            // on a shared device for an hour.
            'Cache-Control' => 'private, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function selectAerialFact(Intake $intake): ?IntakeExternalFact
    {
        $intake->loadMissing('externalFacts');

        $selected = null;
        $selectedPreference = PHP_INT_MAX;

        foreach ($intake->externalFacts as $fact) {
            if ($fact->fact_key !== 'aerial_image') {
                continue;
            }

            $preference = $this->aerialSourcePreference($fact->source);
            if ($preference < $selectedPreference) {
                $selected = $fact;
                $selectedPreference = $preference;
            }
        }

        return $selected;
    }

    private function aerialSourcePreference(string $source): int
    {
        if ($source === PdokAerialImageService::sourceName()) {
            return 0;
        }

        if (str_contains($source, 'fictief demo-voorbeeld')) {
            return 20;
        }

        return 10;
    }
}
