<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\ApiError;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lets through the orders and pricing services: they send the service token.
 */
final class RequireServiceToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('services.orders_token');
        if (! hash_equals($expected, (string) $request->header('X-Service-Token'))) {
            throw new ApiError(401, 'unauthorized');
        }

        return $next($request);
    }
}
