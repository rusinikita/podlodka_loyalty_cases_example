<?php

declare(strict_types=1);

namespace App\Discounts;

use DateTimeImmutable;

final readonly class QuoteRequest
{
    public function __construct(
        public int $userId,
        public int $orderAmount,
        public int $cityId,
        public DateTimeImmutable $orderedAt,
    ) {}
}
