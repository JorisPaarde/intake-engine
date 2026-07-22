<?php

declare(strict_types=1);

namespace App\Support\DevAdmin;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Rijkere systeemcheck voor de dev-admin: superset van HealthController met
 * queue-diepte, cache- en storage-checks. De publieke /health blijft de bron
 * voor deploy-smoke; dit is de leesbare uitbreiding op staging.
 */
final class SystemHealthReport
{
    /**
     * @return array<string, mixed>
     */
    public function collect(): array
    {
        return [
            'app' => [
                'name' => (string) config('app.name'),
                'environment' => app()->environment(),
                'debug' => (bool) config('app.debug'),
                'url' => (string) config('app.url'),
                'php' => PHP_VERSION,
                'laravel' => app()->version(),
                'time' => now()->toIso8601String(),
            ],
            'database' => $this->database(),
            'queue' => $this->queue(),
            'cache' => $this->cache(),
            'storage' => $this->storage(),
            'uploads' => [
                'upload_max_filesize' => (string) ini_get('upload_max_filesize'),
                'post_max_size' => (string) ini_get('post_max_size'),
                'max_file_uploads' => (int) ini_get('max_file_uploads'),
                'app_max_kilobytes' => (int) config('intake.uploads.max_kilobytes'),
            ],
            'image_conversion' => [
                'imagick_loaded' => class_exists(\Imagick::class),
                'heic_read' => $this->imagickSupportsHeicRead(),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function database(): array
    {
        try {
            DB::connection()->getPdo();
            DB::connection()->select('select 1');

            return [
                'ok' => true,
                'connection' => (string) config('database.default'),
                'message' => 'verbonden',
            ];
        } catch (Throwable $e) {
            return [
                'ok' => false,
                'connection' => (string) config('database.default'),
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function queue(): array
    {
        $driver = (string) config('queue.default');
        $result = [
            'ok' => true,
            'driver' => $driver,
            'pending' => null,
            'failed' => null,
        ];

        if ($driver === 'database') {
            try {
                $result['pending'] = DB::table('jobs')->count();
                $result['failed'] = DB::table('failed_jobs')->count();
            } catch (Throwable $e) {
                $result['ok'] = false;
                $result['message'] = $e->getMessage();
            }
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function cache(): array
    {
        try {
            $key = 'devadmin:health:'.Str::random(8);
            Cache::put($key, 'ok', 5);
            $ok = Cache::get($key) === 'ok';
            Cache::forget($key);

            return ['ok' => $ok, 'store' => (string) config('cache.default')];
        } catch (Throwable $e) {
            return ['ok' => false, 'store' => (string) config('cache.default'), 'message' => $e->getMessage()];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function storage(): array
    {
        $disks = [];

        foreach (['local', 'media', 'public'] as $disk) {
            if (config("filesystems.disks.$disk") === null) {
                continue;
            }

            try {
                // Bereikbaarheidsprobe: een misgeconfigureerde disk gooit hier.
                Storage::disk($disk)->exists('probe');
                $disks[$disk] = ['ok' => true];
            } catch (Throwable $e) {
                $disks[$disk] = ['ok' => false, 'message' => $e->getMessage()];
            }
        }

        return $disks;
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
