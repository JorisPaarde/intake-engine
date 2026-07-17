<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domains\Intake\Services\ResolveIntakeByAccessToken;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureCustomerIntakeAccess
{
    public function __construct(
        private readonly ResolveIntakeByAccessToken $resolveIntakeByAccessToken,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = (string) $request->route('token');

        $intake = $this->resolveIntakeByAccessToken->handle($token);

        $request->attributes->set('customer_intake', $intake);

        return $next($request);
    }
}
