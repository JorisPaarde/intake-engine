<?php

declare(strict_types=1);

namespace App\Http\Controllers\Installer;

use App\Domains\Intake\Actions\CreateIntake;
use App\Domains\Intake\Actions\RegenerateIntakeAccessToken;
use App\Domains\Intake\Actions\RevokeIntakeAccess;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeTemplate;
use App\Http\Controllers\Controller;
use App\Http\Requests\Installer\StoreIntakeRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class IntakeController extends Controller
{
    public function create(): View
    {
        $this->authorize('create', Intake::class);

        $templates = IntakeTemplate::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        return view('installer.intakes.create', [
            'templates' => $templates,
        ]);
    }

    public function store(StoreIntakeRequest $request, CreateIntake $createIntake): RedirectResponse
    {
        $intake = $createIntake->handle($request->user(), $request->validated());

        return redirect()
            ->route('intakes.show', $intake)
            ->with('status', 'Opname aangemaakt. De klantlink staat klaar om te kopiëren.');
    }

    public function show(Intake $intake): View
    {
        $this->authorize('view', $intake);

        $intake->load(['templateVersion.template', 'creator']);

        return view('installer.intakes.show', [
            'intake' => $intake,
        ]);
    }

    public function revoke(Request $request, Intake $intake, RevokeIntakeAccess $revokeIntakeAccess): RedirectResponse
    {
        $this->authorize('revoke', $intake);

        $revokeIntakeAccess->handle($intake, $request->user());

        return redirect()
            ->route('intakes.show', $intake)
            ->with('status', 'Klantlink ingetrokken en opname geannuleerd.');
    }

    public function regenerateToken(Request $request, Intake $intake, RegenerateIntakeAccessToken $regenerate): RedirectResponse
    {
        $this->authorize('update', $intake);

        $regenerate->handle($intake, $request->user());

        return redirect()
            ->route('intakes.show', $intake)
            ->with('status', 'Nieuwe klantlink gegenereerd.');
    }
}
