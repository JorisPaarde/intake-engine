<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sluit de dev-admin volledig af buiten local/staging. Bewust een 404 (geen 403):
 * in productie mag de route niet eens bestaan of lekken. Zie config/devadmin.php.
 *
 * AI-traces (`/dev/ai-traces`) eisen daarnaast een e-mail op de allowlist
 * (`DEV_ADMIN_EMAILS`) — anders 403.
 */
final class EnsureDevAccess
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless((bool) config('devadmin.enabled'), 404);

        if ($this->isAiTracesRoute($request) && ! $this->emailAllowed($request)) {
            abort(403);
        }

        return $next($request);
    }

    private function isAiTracesRoute(Request $request): bool
    {
        $route = $request->route();
        $name = is_object($route) ? (string) $route->getName() : '';

        return str_starts_with($name, 'dev.ai-traces');
    }

    private function emailAllowed(Request $request): bool
    {
        $user = $request->user();
        $email = is_object($user) && is_string($user->email ?? null)
            ? strtolower(trim((string) $user->email))
            : '';

        if ($email === '') {
            return false;
        }

        /** @var list<string> $allowlist */
        $allowlist = config('devadmin.emails', []);

        return in_array($email, $allowlist, true);
    }
}
