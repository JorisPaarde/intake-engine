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
            'public_demo_guide_step' => null,
        ]);

        if ($path === 'customer') {
            return redirect()
                ->to($intake->customerUrl())
                ->with(
                    'status',
                    'U bekijkt nu wat de klant ziet. In productie zou de klant een e-mail met deze link krijgen.',
                );
        }

        return redirect()
            ->route('intakes.workspace', $intake)
            ->with(
                'status',
                'U doet de opname zelf. De klanttoegang blijft uit totdat u een taak voor de klant activeert.',
            );
    }
}
