<?php

declare(strict_types=1);

namespace App\Discounts;

final readonly class Quote
{
    public function __construct(
        public ?int $discountId = null,
        public int $amount = 0,
    ) {}
}
