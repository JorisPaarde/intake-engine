<?php

declare(strict_types=1);

namespace App\Http\Controllers\Installer;

use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeExternalFact;
use App\Domains\Intake\Services\ExternalFactPresenter;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves the stored aerial JPEG via an authorized, cacheable route so the
 * workspace/show HTML does not embed a multi-hundred-KB data URI.
 */
final class IntakeAerialImageController extends Controller
{
    public function __construct(
        private readonly ExternalFactPresenter $externalFactPresenter,
    ) {}

    public function show(Intake $intake): StreamedResponse
    {
        $this->authorize('view', $intake);

        $fact = $this->externalFactPresenter->selectAerialFact($intake);

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
}
