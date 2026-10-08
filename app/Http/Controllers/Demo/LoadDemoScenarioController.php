<?php

declare(strict_types=1);

namespace App\Http\Controllers\Demo;

use App\Domains\Intake\Actions\LoadDemoSurveyScenario;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Services\PublicDemoSession;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class LoadDemoScenarioController extends Controller
{
    public function __invoke(
        Request $request,
        Intake $intake,
        LoadDemoSurveyScenario $loadDemoSurveyScenario,
        PublicDemoSession $publicDemoSession,
    ): RedirectResponse {
        $this->authorize('update', $intake);
        abort_unless($intake->is_demo, 404);

        $example = $loadDemoSurveyScenario->handle($intake, $request->user());

        // Keep own intake reachable; set sample as the active view.
        $publicDemoSession->rememberIntake($request, (int) $intake->id, setActive: false);
        $publicDemoSession->rememberIntake($request, (int) $example->id, setActive: true);
        $request->session()->put([
            'public_demo_scenario_loaded' => true,
            'public_demo_guide_step' => null,
        ]);

        return redirect()
            ->route('intakes.workspace', $example)
            ->with(
                'status',
                'Voorbeelddossier geopend in een aparte demo-opname. Je eigen aanvraag blijft ongewijzigd. Geen echte klant, geen mail.',
            );
    }
}
