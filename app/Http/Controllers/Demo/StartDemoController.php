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

        $intake = $startDemoIntake->handle();
        $creator = $intake->creator;

        abort_unless($creator !== null, 500);

        Auth::login($creator);
        $request->session()->regenerate();
        $request->session()->put([
            'public_demo_intake_id' => $intake->id,
            'public_demo_company_id' => $intake->company_id,
            'public_demo_expires_at' => $intake->token_expires_at?->toIso8601String(),
        ]);

        return redirect()
            ->route('intakes.workspace', $intake)
            ->with(
                'status',
                'De interactieve demo staat klaar. Alles is fictief en wordt na '
                    .max(1, (int) config('intake.demo.ttl_hours', 2))
                    .' uur verwijderd.',
            );
    }
}
