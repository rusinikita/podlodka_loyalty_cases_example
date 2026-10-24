<?php

declare(strict_types=1);

namespace App\Admin;

use App\Http\ApiError;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class RequireRole
{
    public function handle(Request $request, Closure $next, string $min): Response
    {
        if (Operator::of($request)->role->rank() < Role::from($min)->rank()) {
            throw new ApiError(403, 'forbidden');
        }

        return $next($request);
    }

    public static function atLeast(Role|string $role): string
    {
        return self::class.':'.($role instanceof Role ? $role->value : $role);
    }
}
