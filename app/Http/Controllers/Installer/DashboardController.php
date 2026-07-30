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

        if ($user === null || ! (bool) config('intake.demo.enabled', true)) {
            return false;
        }

        $publicDemoIntakeId = $request->session()->get('public_demo_intake_id');

        if (is_numeric($publicDemoIntakeId)
            && Intake::query()
                ->whereKey((int) $publicDemoIntakeId)
                ->where('company_id', $user->company_id)
                ->where('created_by', $user->id)
                ->where('is_demo', true)
                ->exists()) {
            return true;
        }

        return ! app()->isProduction()
            && $user->email === (string) config('intake.demo.user_email', 'demo@intake-engine.invalid');
    }
}
