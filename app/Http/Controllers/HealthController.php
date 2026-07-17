<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Tijdelijke health-check om te verifiëren dat een deploy werkt.
 * Bewijst: app boot, juiste omgeving, DB-verbinding en queue-driver.
 * Verwijder of vervang zodra de eerste echte feature live is.
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
            'time' => now()->toIso8601String(),
        ], 200, [], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }
}
