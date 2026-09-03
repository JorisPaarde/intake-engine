<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Domains\Intake\Services\PublicDemoSession;
use App\Domains\Intake\Services\PublicDemoWorkspaceProvisioner;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class AuthenticatedSessionController extends Controller
{
    /**
     * Display the login view.
     */
    public function create(): View
    {
        return view('auth.login');
    }

    /**
     * Handle an incoming authentication request.
     */
    public function store(
        LoginRequest $request,
        PublicDemoSession $publicDemoSession,
        PublicDemoWorkspaceProvisioner $workspaceProvisioner,
    ): RedirectResponse {
        $request->authenticate();

        $request->session()->regenerate();

        // Leftover public-demo flags + url.intended from redirect()->guest()
        // would send a real installer to a purged demo intake → 404 (BL-091).
        $hadDemoResidue = $publicDemoSession->hasSessionFlags($request);
        $user = $request->user();

        if ($hadDemoResidue || ($user !== null && ! $workspaceProvisioner->isEphemeralUser($user))) {
            $publicDemoSession->forget($request);
        }

        if ($hadDemoResidue) {
            $request->session()->forget('url.intended');
        }

        return redirect()->intended(route('dashboard', absolute: false));
    }

    /**
     * Destroy an authenticated session.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $wasPublicDemo = (bool) $request->session()->get('public_demo_mode', false)
            || $request->session()->has('public_demo_intake_id');

        Auth::guard('web')->logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        if ($wasPublicDemo) {
            return redirect()->route('demo.ended', ['reason' => 'ended']);
        }

        return redirect('/');
    }
}
