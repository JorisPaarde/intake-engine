<?php

declare(strict_types=1);

namespace App\Http\Controllers\Customer;

use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeUpload;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class IntakeUploadController extends Controller
{
    public function show(Request $request, string $token, IntakeUpload $upload): StreamedResponse
    {
        $intake = $request->attributes->get('customer_intake');

        if (! $intake instanceof Intake || $upload->intake_id !== $intake->id) {
            abort(404);
        }

        return $this->stream($upload);
    }

    private function stream(IntakeUpload $upload): StreamedResponse
    {
        $disk = Storage::disk($upload->disk);

        if (! $disk->exists($upload->path)) {
            abort(404);
        }

        $headers = [
            'Content-Type' => $upload->mime_type,
            'Cache-Control' => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ];

        if (! str_starts_with($upload->mime_type, 'image/')) {
            return $disk->download($upload->path, $upload->original_filename, $headers);
        }

        return $disk->response($upload->path, $upload->original_filename, $headers);
    }
}
