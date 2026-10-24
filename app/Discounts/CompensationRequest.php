<?php

declare(strict_types=1);

namespace App\Discounts;

/**
 * Discount granted by an operator in the admin panel.
 */
final readonly class CompensationRequest
{
    public function __construct(
        public int $userId,
        public int $percent,
        public ?int $maxAmount,
        public int $ttlDays,
        public string $operator,
        public string $comment,
    ) {}
}
