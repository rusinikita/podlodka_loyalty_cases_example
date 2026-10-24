<?php

declare(strict_types=1);

namespace App\Discounts;

use DateTimeImmutable;

/**
 * Campaign conditions checked when a discount is used for an order.
 */
final readonly class OrderConditions
{
    public function __construct(
        public ?string $timeFrom = null,
        public ?string $timeTo = null,
        public int $minOrderAmount = 0,
    ) {}

    /**
     * @param  array<string, mixed>  $conditions
     */
    public static function fromArray(array $conditions): self
    {
        return new self(
            timeFrom: isset($conditions['time_from']) ? (string) $conditions['time_from'] : null,
            timeTo: isset($conditions['time_to']) ? (string) $conditions['time_to'] : null,
            minOrderAmount: (int) ($conditions['min_order_amount'] ?? 0),
        );
    }

    public function match(int $orderAmount, DateTimeImmutable $orderedAt): bool
    {
        if ($this->minOrderAmount > 0 && $orderAmount < $this->minOrderAmount) {
            return false;
        }
        if ($this->timeFrom === null || $this->timeTo === null) {
            return true;
        }

        $hm = $orderedAt->format('H:i');
        if ($this->timeFrom <= $this->timeTo) {
            return $hm >= $this->timeFrom && $hm < $this->timeTo;
        }

        // The interval crosses midnight, e.g. 22:00–06:00.
        return $hm >= $this->timeFrom || $hm < $this->timeTo;
    }
}
