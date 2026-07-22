<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dev;

use App\Domains\AI\Models\AiRun;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeActivityEvent;
use App\Domains\Intake\Models\IntakeExternalFact;
use App\Http\Controllers\Controller;
use App\Support\DevAdmin\ServiceStatusReport;
use Illuminate\View\View;

final class DevDashboardController extends Controller
{
    public function __invoke(ServiceStatusReport $status): View
    {
        return view('dev.index', [
            'services' => $status->services(),
            'counts' => [
                'intakes' => Intake::withTrashed()->count(),
                'ai_runs' => AiRun::count(),
                'external_facts' => IntakeExternalFact::count(),
                'events' => IntakeActivityEvent::count(),
            ],
            'recentAiRuns' => AiRun::query()->with('intake')->latest('started_at')->limit(8)->get(),
            'recentActivity' => IntakeActivityEvent::query()->with('intake')->latest('created_at')->limit(12)->get(),
        ]);
    }
}
