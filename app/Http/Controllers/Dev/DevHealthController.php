<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dev;

use App\Http\Controllers\Controller;
use App\Support\DevAdmin\SystemHealthReport;
use Illuminate\View\View;

final class DevHealthController extends Controller
{
    public function __invoke(SystemHealthReport $health): View
    {
        return view('dev.health', [
            'health' => $health->collect(),
        ]);
    }
}
