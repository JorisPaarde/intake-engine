<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Health-check om te verifiëren dat een deploy werkt.
 * Bewijst: app boot, omgeving, DB-verbinding, queue-driver en PHP upload-limieten.
 */
final class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $database = 'ok';
        try {
            DB::connection()->getPdo();
            DB::connection()->select('select 1');
        } catch (Throwable $e) {
            $database = 'fout: '.$e->getMessage();
        }

        return response()->json([
            'app' => config('app.name'),
            'status' => 'ok',
            'message' => 'Hello from the Intake Engine 👋',
            'environment' => app()->environment(),
            'php' => PHP_VERSION,
            'laravel' => app()->version(),
            'database' => $database,
            'queue' => config('queue.default'),
            'php_upload' => [
                'upload_max_filesize' => (string) ini_get('upload_max_filesize'),
                'post_max_size' => (string) ini_get('post_max_size'),
                'max_file_uploads' => (int) ini_get('max_file_uploads'),
                'app_max_kilobytes' => (int) config('intake.uploads.max_kilobytes'),
            ],
            'image_conversion' => [
                'imagick_loaded' => class_exists(\Imagick::class),
                'heic_read' => $this->imagickSupportsHeicRead(),
            ],
            'time' => now()->toIso8601String(),
        ], 200, [], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    private function imagickSupportsHeicRead(): bool
    {
        if (! class_exists(\Imagick::class)) {
            return false;
        }

        try {
            return \Imagick::queryFormats('HEIC') !== []
                || \Imagick::queryFormats('HEIF') !== [];
        } catch (Throwable) {
            return false;
        }
    }
}
