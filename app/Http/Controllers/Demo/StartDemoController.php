<?php

declare(strict_types=1);

namespace App\Http\Controllers\Demo;

use App\Domains\Intake\Actions\StartDemoIntake;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class StartDemoController extends Controller
{
    public function __invoke(Request $request, StartDemoIntake $startDemoIntake): RedirectResponse
    {
        if (! (bool) config('intake.demo.enabled', true)) {
            throw new NotFoundHttpException;
        }

        $creator = $startDemoIntake->handle();

        Auth::login($creator);
        $request->session()->regenerate();

        $ttlHours = max(1, (int) config('intake.demo.ttl_hours', 2));

        $request->session()->put([
            'public_demo_mode' => true,
            'public_demo_company_id' => $creator->company_id,
            'public_demo_expires_at' => now()->addHours($ttlHours)->toIso8601String(),
            'public_demo_guide_step' => 'welcome',
            'public_demo_intake_id' => null,
            'public_demo_path_chosen' => null,
            'public_demo_short_customer' => false,
            'public_demo_scenario_loaded' => false,
        ]);

        return redirect()
            ->route('dashboard')
            ->with(
                'status',
                'Welkom in de interactieve demo. Alles is fictief en wordt na '
                    .$ttlHours
                    .' uur verwijderd.',
            )
            ->with('demo_coachmark', 'welcome');
    }
}
