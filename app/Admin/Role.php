<?php

declare(strict_types=1);

namespace App\Admin;

/**
 * Roles of the admin panel, from the lowest. Each role can do everything the
 * previous ones can (docs/admin_permissions.md).
 */
enum Role: string
{
    case SupportL1 = 'support_l1';
    case SupportL2 = 'support_l2';
    case LoyaltyOperator = 'loyalty_operator';
    case LoyaltyAdmin = 'loyalty_admin';

    public function rank(): int
    {
        return match ($this) {
            self::SupportL1 => 1,
            self::SupportL2 => 2,
            self::LoyaltyOperator => 3,
            self::LoyaltyAdmin => 4,
        };
    }
}
