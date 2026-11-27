<?php

declare(strict_types=1);

namespace App\Http\Controllers\Customer;

use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeExternalFact;
use App\Domains\Intake\Services\ExternalFactPresenter;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Luchtfoto van de eigen woning op de klantpagina “Dit hebben we gevonden” (BL-174).
 * Toegang via de klanttoken (middleware `customer.intake`), net als de klantfoto’s.
 */
final class AerialImageController extends Controller
{
    public function __construct(
        private readonly ExternalFactPresenter $externalFactPresenter,
    ) {}

    public function show(Request $request, string $token): StreamedResponse
    {
        $intake = $request->attributes->get('customer_intake');
        if (! $intake instanceof Intake) {
            abort(404);
        }

        $fact = $this->externalFactPresenter->selectAerialFact($intake);
        if (! $fact instanceof IntakeExternalFact) {
            abort(404);
        }

        $disk = $fact->value['media_disk'] ?? null;
        $path = $fact->value['media_path'] ?? null;

        if (! is_string($disk) || $disk === ''
            || ! is_string($path) || $path === ''
            || ($fact->value['mime_type'] ?? null) !== 'image/jpeg'
            || ! Storage::disk($disk)->exists($path)) {
            abort(404);
        }

        return Storage::disk($disk)->response($path, 'luchtfoto.jpg', [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
