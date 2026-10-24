<?php

declare(strict_types=1);

namespace App\Discounts;

use DateTimeImmutable;
use DateTimeInterface;

/**
 * Row of user_discounts. Either percent (with an optional max_amount cap) or
 * amount is set.
 */
final readonly class Discount
{
    public function __construct(
        public int $id,
        public int $userId,
        public ?int $campaignId,
        public ?string $channel,
        public ?string $paymentId,
        public ?int $percent,
        public ?int $amount,
        public ?int $maxAmount,
        public DateTimeImmutable $expiresAt,
        public ?DateTimeImmutable $usedAt,
        public ?int $usedOrderId,
        public ?string $createdBy,
        public ?string $comment,
        public DateTimeImmutable $createdAt,
    ) {}

    /**
     * The discount for an order of the given amount.
     */
    public function value(int $orderAmount): int
    {
        if ($this->percent !== null) {
            $value = intdiv($orderAmount * $this->percent, 100);

            return $this->maxAmount !== null ? min($value, $this->maxAmount) : $value;
        }
        if ($this->amount !== null) {
            return min($this->amount, $orderAmount);
        }

        return 0;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'campaign_id' => $this->campaignId,
            'percent' => $this->percent,
            'amount' => $this->amount,
            'max_amount' => $this->maxAmount,
            'expires_at' => $this->expiresAt->format(DateTimeInterface::RFC3339_EXTENDED),
            'used_at' => $this->usedAt?->format(DateTimeInterface::RFC3339_EXTENDED),
            'created_at' => $this->createdAt->format(DateTimeInterface::RFC3339_EXTENDED),
        ];
    }
}
