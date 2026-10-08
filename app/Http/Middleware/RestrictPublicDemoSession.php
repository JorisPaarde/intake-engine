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
 *
 * Dossier detail actions that are visible in the demo (adres opnieuw, AI-
 * aandachtspunten, beoordeling, rapport/PDF) must stay on the allowlist so a
 * save does not 404 or bounce the visitor to /login (BL-091).
 *
 * Own intake + sample dossier may both be reachable once loaded; membership
 * is checked via public_demo_intake_ids (with legacy single-id migration).
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
        $hasIntake = $this->publicDemoSession->hasAnyIntake($request);

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
            || $routeName === 'intakes.pdf.regenerate'
            || $routeName === 'intakes.report'
            || $routeName === 'intakes.review'
            || $routeName === 'intakes.address-enrichment.retry'
            || $routeName === 'intakes.attention.suggest'
            || $routeName === 'intakes.attention.accept'
            || $routeName === 'intakes.attention.dismiss'
            || $routeName === 'intakes.regenerate-token'
            || $routeName === 'intakes.revoke'
            || $routeName === 'installer.uploads.show'
            || $routeName === 'intakes.aerial.show'
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

        $routeIntake = $request->route('intake');

        if ($routeIntake instanceof Intake) {
            // Outside the allowlist → hard 404 (never open another demo's dossier).
            abort_unless(
                $this->publicDemoSession->allowsIntake($request, (int) $routeIntake->id),
                404,
            );

            $resolved = $this->publicDemoSession->resolveIntake($request, (int) $routeIntake->id);

            if ($resolved === null) {
                // Listed but no longer resolvable (TTL / ownership) — end the demo.
                return $this->expireSession($request);
            }

            abort_unless($routeIntake->is($resolved), 404);

            // Keep coach/rapport pointed at the intake the visitor is viewing.
            $this->publicDemoSession->rememberIntake($request, (int) $routeIntake->id, setActive: true);

            return $next($request);
        }

        $demoIntake = $this->publicDemoSession->resolveIntake($request);

        if ($demoIntake === null) {
            // Allowed list may still hold a stale id after TTL — expire cleanly.
            return $this->expireSession($request);
        }

        return $next($request);
    }

    private function expireSession(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('demo.ended', ['reason' => 'expired']);
    }
}
