<?php

declare(strict_types=1);

namespace App\Http\Controllers\Installer;

use App\Domains\Intake\Models\Intake;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request): View
    {
        $this->authorize('viewAny', Intake::class);

        $intakes = Intake::query()
            ->with(['templateVersion.template'])
            ->latest()
            ->paginate(20);

        return view('installer.dashboard', [
            'intakes' => $intakes,
        ]);
    }
}
