<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Services\PublicDemoWorkspaceProvisioner;
use App\Models\User;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps the automatically authenticated public demo user inside its one
 * temporary dossier. This prevents an anonymous visitor from using ordinary
 * installer routes to create real intakes or trigger external effects.
 */
final class RestrictPublicDemoSession
{
    public function __construct(
        private readonly PublicDemoWorkspaceProvisioner $workspaceProvisioner,
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

        $demoIntakeId = $request->session()->get('public_demo_intake_id');

        if (! is_numeric($demoIntakeId)) {
            return $this->expireSession($request);
        }

        $demoIntake = Intake::query()
            ->whereKey((int) $demoIntakeId)
            ->where('company_id', $user->company_id)
            ->where('created_by', $user->id)
            ->where('is_demo', true)
            ->where(
                'created_at',
                '>',
                now()->subHours(max(1, (int) config('intake.demo.ttl_hours', 2))),
            )
            ->first();

        if ($demoIntake === null) {
            return $this->expireSession($request);
        }

        $route = $request->route();
        $routeName = $route instanceof Route ? (string) $route->getName() : '';
        $allowed = $routeName === 'dashboard'
            || $routeName === 'intakes.show'
            || $routeName === 'installer.uploads.show'
            || $routeName === 'logout'
            || str_starts_with($routeName, 'intakes.workspace');

        abort_unless($allowed, 404);

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
