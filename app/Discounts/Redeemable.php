<?php

declare(strict_types=1);

namespace App\Discounts;

/**
 * Unused discount together with the order conditions of its campaign.
 */
final readonly class Redeemable
{
    public function __construct(
        public Discount $discount,
        public OrderConditions $conditions,
    ) {}
}
