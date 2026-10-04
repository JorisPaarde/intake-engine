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

        $example = $loadDemoSurveyScenario->handle($intake, $request->user());

        $request->session()->put([
            'public_demo_scenario_loaded' => true,
            'public_demo_intake_id' => $example->id,
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
