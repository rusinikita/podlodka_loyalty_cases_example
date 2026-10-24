<?php

declare(strict_types=1);

namespace App\Discounts;

use DateTimeImmutable;

/**
 * Discount granted by a campaign.
 */
final readonly class GrantRequest
{
    public function __construct(
        public int $userId,
        public int $campaignId,
        public ?string $channel,
        public ?string $paymentId,
        public int $percent,
        public int $amount,
        public int $maxAmount,
        public DateTimeImmutable $expiresAt,
        // Sends the user a push about the discount for the next order.
        public bool $notify,
    ) {}
}
