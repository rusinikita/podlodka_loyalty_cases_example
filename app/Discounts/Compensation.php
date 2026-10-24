<?php

declare(strict_types=1);

namespace App\Discounts;

/**
 * Limits of a compensation discount, from config/app.yaml.
 */
final readonly class Compensation
{
    public function __construct(
        public int $maxPercent,
        public int $ttlDays,
        public string $role,
    ) {}
}
