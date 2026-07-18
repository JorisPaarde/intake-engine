<?php

declare(strict_types=1);

namespace App\Http\Controllers\Demo;

use App\Domains\Intake\Actions\StartDemoIntake;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class StartDemoController extends Controller
{
    public function __invoke(StartDemoIntake $startDemoIntake): RedirectResponse
    {
        if (! (bool) config('intake.demo.enabled', false)) {
            throw new NotFoundHttpException;
        }

        $intake = $startDemoIntake->handle();

        return redirect()->route('customer.intake.show', ['token' => $intake->access_token]);
    }
}
