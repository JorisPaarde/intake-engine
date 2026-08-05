<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Services\PublicDemoSession;
use App\Domains\Intake\Services\PublicDemoWorkspaceProvisioner;
use App\Models\User;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps the automatically authenticated public demo user inside the guided
 * demo path: dashboard → one intake create → show/workspace (and logout).
 */
final class RestrictPublicDemoSession
{
    public function __construct(
        private readonly PublicDemoWorkspaceProvisioner $workspaceProvisioner,
        private readonly PublicDemoSession $publicDemoSession,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User
            || ! str_starts_with($user->email, 'installateur+')
            || ! str_ends_with($user->email, '@demo.invalid')
            || ! $this->workspaceProvisioner->isEphemeralUser($user)) {
            return $next($request);
        }

        if (! $this->publicDemoSession->isActive($request)) {
            return $this->expireSession($request);
        }

        $route = $request->route();
        $routeName = $route instanceof Route ? (string) $route->getName() : '';
        $intakeId = $this->publicDemoSession->intakeId($request);
        $hasIntake = $intakeId !== null;

        $allowedWithoutIntake = in_array($routeName, [
            'dashboard',
            'intakes.create',
            'intakes.store',
            'address-suggestions',
            'logout',
        ], true);

        $allowedWithIntake = $routeName === 'dashboard'
            || $routeName === 'intakes.show'
            || $routeName === 'intakes.store'
            || $routeName === 'intakes.pdf'
            || $routeName === 'installer.uploads.show'
            || $routeName === 'logout'
            || $routeName === 'demo.path.choose'
            || $routeName === 'demo.scenario.load'
            || $routeName === 'demo.report-pdf'
            || str_starts_with($routeName, 'intakes.workspace');

        if (! $hasIntake) {
            abort_unless($allowedWithoutIntake, 404);

            return $next($request);
        }

        // Block a second intake create once the demo dossier exists.
        if (in_array($routeName, ['intakes.create', 'intakes.store'], true)) {
            abort(404);
        }

        abort_unless($allowedWithIntake, 404);

        $demoIntake = $this->publicDemoSession->resolveIntake($request);

        if ($demoIntake === null) {
            return $this->expireSession($request);
        }

        $routeIntake = $request->route('intake');

        if ($routeIntake instanceof Intake) {
            abort_unless($routeIntake->is($demoIntake), 404);
        }

        return $next($request);
    }

    private function expireSession(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
