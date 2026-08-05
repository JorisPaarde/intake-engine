<?php

declare(strict_types=1);

namespace App\Http\Controllers\Demo;

use App\Domains\Intake\Actions\LoadDemoSurveyScenario;
use App\Domains\Intake\Models\Intake;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class LoadDemoScenarioController extends Controller
{
    public function __invoke(
        Request $request,
        Intake $intake,
        LoadDemoSurveyScenario $loadDemoSurveyScenario,
    ): RedirectResponse {
        $this->authorize('update', $intake);
        abort_unless($intake->is_demo, 404);

        $loadDemoSurveyScenario->handle($intake, $request->user());

        $request->session()->put([
            'public_demo_scenario_loaded' => true,
            'public_demo_guide_step' => 'sample_loaded',
        ]);

        return redirect()
            ->route('intakes.workspace', $intake)
            ->with('demo_coachmark', 'sample_loaded')
            ->with(
                'status',
                'Voorbeelddossier geladen. Je kunt hierna nog AI-voorstellen vernieuwen of eigen foto’s laten analyseren. E-mail blijft uit.',
            );
    }
}
