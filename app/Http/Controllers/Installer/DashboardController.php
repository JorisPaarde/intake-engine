<?php

declare(strict_types=1);

namespace App\Http\Controllers\Installer;

use App\Domains\Intake\Models\Intake;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request): View
    {
        $this->authorize('viewAny', Intake::class);

        $user = $request->user();
        $showingDemoIntakes = $this->shouldShowDemoIntakes($request);

        $query = Intake::query()
            ->where('company_id', $user?->company_id)
            ->with(['templateVersion.template']);

        if ($showingDemoIntakes && $user !== null) {
            $query
                ->where('is_demo', true)
                ->where('created_by', $user->id);
        } else {
            $query->where('is_demo', false);
        }

        $intakes = $query
            // "Nieuw afgerond" (completed, nog niet beoordeeld) bovenaan — BL-014.
            ->orderByRaw("CASE WHEN status = 'completed' AND reviewed_at IS NULL THEN 0 ELSE 1 END")
            ->latest()
            ->paginate(20);

        return view('installer.dashboard', [
            'intakes' => $intakes,
            'showingDemoIntakes' => $showingDemoIntakes,
        ]);
    }

    private function shouldShowDemoIntakes(Request $request): bool
    {
        $user = $request->user();

        return $user !== null
            && ! app()->isProduction()
            && (bool) config('intake.demo.enabled', true)
            && $user->email === (string) config('intake.demo.user_email', 'demo@intake-engine.invalid');
    }
}
