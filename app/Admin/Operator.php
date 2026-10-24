<?php

declare(strict_types=1);

namespace App\Admin;

use Illuminate\Http\Request;

/**
 * Operator passed by the admin panel in headers.
 */
final readonly class Operator
{
    public function __construct(
        public string $login,
        public Role $role,
    ) {}

    public static function of(Request $request): self
    {
        $operator = $request->attributes->get(self::class);
        assert($operator instanceof self);

        return $operator;
    }
}
