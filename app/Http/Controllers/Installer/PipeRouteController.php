<?php

declare(strict_types=1);

namespace App\Http\Controllers\Installer;

use App\Domains\Intake\Actions\ApprovePipeRoute;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\PipeRouteSession;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class PipeRouteController extends Controller
{
    /**
     * De installateur keurt de door AI voorgestelde leidingroute goed of af. Bewust een
     * expliciete menselijke stap (vast ontwerpprincipe): AI levert alleen een voorzet.
     */
    public function review(
        Request $request,
        Intake $intake,
        PipeRouteSession $session,
        ApprovePipeRoute $approvePipeRoute,
    ): RedirectResponse {
        $this->authorize('update', $intake);

        abort_unless($session->intake_id === $intake->id, 404);

        $validated = $request->validate([
            'decision' => ['required', 'in:approve,reject'],
        ]);

        $approvePipeRoute->handle($session, $request->user(), $validated['decision'] === 'approve');

        return back()->with('status', $validated['decision'] === 'approve'
            ? 'Leidingroute goedgekeurd.'
            : 'Leidingroute afgekeurd.');
    }
}
