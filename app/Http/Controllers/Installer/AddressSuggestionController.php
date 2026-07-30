<?php

declare(strict_types=1);

namespace App\Http\Controllers\Installer;

use App\Domains\Intake\Services\PdokAddressService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Throwable;

final class AddressSuggestionController extends Controller
{
    public function __invoke(Request $request, PdokAddressService $pdok): JsonResponse
    {
        if ($request->hasAny(['postal_code', 'house_number', 'house_number_addition'])) {
            $validator = Validator::make($request->query(), [
                'postal_code' => ['required', 'string', 'regex:/^[1-9]\d{3}\s?[A-Za-z]{2}$/'],
                'house_number' => ['required', 'integer', 'min:1', 'max:999999'],
                'house_number_addition' => ['nullable', 'string', 'max:20', 'regex:/^[A-Za-z0-9\-\s]+$/'],
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'message' => 'Controleer de postcode en het huisnummer.',
                    'errors' => $validator->errors()->toArray(),
                ], 422);
            }

            /** @var array{postal_code: string, house_number: int|string, house_number_addition?: string|null} $validated */
            $validated = $validator->validated();
            /** @return list<array{id: string, label: string, address_line: string, postal_code: string, house_number: int, house_number_addition: string|null, city: string}> */
            $lookup = fn (): array => $pdok->suggestForPostalAddress(
                $validated['postal_code'],
                (int) $validated['house_number'],
                $validated['house_number_addition'] ?? null,
            );
        } else {
            $validator = Validator::make($request->query(), [
                'q' => ['required', 'string', 'min:3', 'max:160'],
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'message' => 'Controleer de zoekopdracht.',
                    'errors' => $validator->errors()->toArray(),
                ], 422);
            }

            /** @var array{q: string} $validated */
            $validated = $validator->validated();
            /** @return list<array{id: string, label: string, address_line: string, postal_code: string, house_number: int, house_number_addition: string|null, city: string}> */
            $lookup = fn (): array => $pdok->suggest($validated['q']);
        }

        try {
            return response()->json(['data' => $lookup()]);
        } catch (Throwable $exception) {
            Log::warning('PDOK address suggestions failed.', [
                'user_id' => $request->user()?->id,
                'exception' => $exception::class,
            ]);

            return response()->json(['data' => []]);
        }
    }
}
