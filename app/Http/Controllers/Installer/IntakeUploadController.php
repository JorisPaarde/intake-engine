<?php

declare(strict_types=1);

namespace App\Http\Controllers\Installer;

use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeUpload;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class IntakeUploadController extends Controller
{
    public function show(Intake $intake, IntakeUpload $upload): StreamedResponse
    {
        if ($upload->intake_id !== $intake->id) {
            abort(404);
        }

        $this->authorize('view', $intake);

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
