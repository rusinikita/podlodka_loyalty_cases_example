<?php

declare(strict_types=1);

namespace App\Admin;

use App\Http\ApiError;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class IdentifyOperator
{
    public function handle(Request $request, Closure $next): Response
    {
        $login = (string) $request->header('X-Operator-Login');
        $role = Role::tryFrom((string) $request->header('X-Operator-Role'));
        if ($login === '' || $role === null) {
            throw new ApiError(403, 'forbidden');
        }

        $request->attributes->set(Operator::class, new Operator($login, $role));

        return $next($request);
    }
}
