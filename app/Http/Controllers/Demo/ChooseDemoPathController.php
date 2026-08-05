<?php

declare(strict_types=1);

namespace App\Http\Controllers\Demo;

use App\Domains\Intake\Actions\ChooseDemoContributionPath;
use App\Domains\Intake\Models\Intake;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class ChooseDemoPathController extends Controller
{
    public function __invoke(
        Request $request,
        Intake $intake,
        ChooseDemoContributionPath $chooseDemoContributionPath,
    ): RedirectResponse {
        $this->authorize('update', $intake);
        abort_unless($intake->is_demo, 404);

        $validated = $request->validate([
            'path' => ['required', Rule::in(['customer', 'installer'])],
        ]);

        $path = (string) $validated['path'];
        $intake = $chooseDemoContributionPath->handle($intake, $request->user(), $path);

        $request->session()->put([
            'public_demo_path_chosen' => $path,
            'public_demo_short_customer' => $path === 'customer',
            'public_demo_guide_step' => $path === 'customer' ? 'customer_start' : 'installer_start',
        ]);

        if ($path === 'customer') {
            return redirect()
                ->to($intake->customerUrl())
                ->with('demo_coachmark', 'customer_start')
                ->with(
                    'status',
                    'Je bekijkt nu de klantweergave. In productie zou de klant een e-mail met deze link krijgen.',
                );
        }

        return redirect()
            ->route('intakes.workspace', $intake)
            ->with('demo_coachmark', 'installer_start')
            ->with(
                'status',
                'Je doet de opname zelf. De klanttoegang blijft uit totdat je een gerichte klanttaak activeert.',
            );
    }
}
