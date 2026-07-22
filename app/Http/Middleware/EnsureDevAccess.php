<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sluit de dev-admin volledig af buiten local/staging. Bewust een 404 (geen 403):
 * in productie mag de route niet eens bestaan of lekken. Zie config/devadmin.php.
 */
final class EnsureDevAccess
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless((bool) config('devadmin.enabled'), 404);

        return $next($request);
    }
}
