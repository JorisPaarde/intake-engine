<?php

declare(strict_types=1);

namespace App\Http\Controllers\Installer;

use App\Domains\Intake\Services\PdokAddressService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

final class AddressSuggestionController extends Controller
{
    public function __invoke(Request $request, PdokAddressService $pdok): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['required', 'string', 'min:3', 'max:160'],
        ]);

        try {
            return response()->json([
                'data' => $pdok->suggest($validated['q']),
            ]);
        } catch (Throwable $exception) {
            Log::warning('PDOK address suggestions failed.', [
                'user_id' => $request->user()?->id,
                'exception' => $exception::class,
            ]);

            return response()->json(['data' => []]);
        }
    }
}
