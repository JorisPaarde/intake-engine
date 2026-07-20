<?php

declare(strict_types=1);

namespace App\Http\Controllers\Installer;

use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Services\IntakeMetricsService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class MetricsController extends Controller
{
    public function __invoke(Request $request, IntakeMetricsService $metricsService): View
    {
        $this->authorize('viewAny', Intake::class);

        $validated = $request->validate([
            'period' => ['nullable', 'in:30,90,all'],
        ]);
        $period = (string) ($validated['period'] ?? '30');
        $createdSince = $period === 'all'
            ? null
            : now()->subDays((int) $period)->startOfDay();

        return view('installer.metrics', [
            'metrics' => $metricsService->calculate($createdSince),
            'period' => $period,
        ]);
    }
}
