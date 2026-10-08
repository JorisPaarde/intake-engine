<?php

declare(strict_types=1);

namespace App\Http\Controllers\Installer;

use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Services\PublicDemoSession;
use App\Domains\Intake\Support\CustomerTaskStatusPresenter;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(
        Request $request,
        PublicDemoSession $publicDemoSession,
        CustomerTaskStatusPresenter $customerTaskStatus,
    ): View {
        $this->authorize('viewAny', Intake::class);

        $user = $request->user();
        $isPublicDemo = $publicDemoSession->isActive($request);
        $showingDemoIntakes = $this->shouldShowDemoIntakes($request, $publicDemoSession);

        $query = Intake::query()
            ->where('company_id', $user?->company_id)
            ->with(['templateVersion.template', 'followUpRounds.items', 'answers']);

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

        /** @var Collection<int, array{label: string, short_label: string, link_active: bool, percent: int|null}> $taskStatuses */
        $taskStatuses = $intakes->getCollection()->mapWithKeys(
            static fn (Intake $intake): array => [$intake->id => $customerTaskStatus->present($intake)],
        );

        return view('installer.dashboard', [
            'intakes' => $intakes,
            'customerTaskStatuses' => $taskStatuses,
            'showingDemoIntakes' => $showingDemoIntakes,
            'isPublicDemo' => $isPublicDemo,
            'publicDemoHasIntake' => $publicDemoSession->hasAnyIntake($request),
        ]);
    }

    private function shouldShowDemoIntakes(Request $request, PublicDemoSession $publicDemoSession): bool
    {
        $user = $request->user();

        if ($user === null || ! (bool) config('intake.demo.enabled', true)) {
            return false;
        }

        if ($publicDemoSession->isActive($request)) {
            return true;
        }

        return ! app()->isProduction()
            && $user->email === (string) config('intake.demo.user_email', 'demo@intake-engine.invalid');
    }
}
