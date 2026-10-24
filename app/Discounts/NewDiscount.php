<?php

declare(strict_types=1);

namespace App\Discounts;

use DateTimeImmutable;

final readonly class NewDiscount
{
    public function __construct(
        public int $userId,
        public DateTimeImmutable $expiresAt,
        public ?int $campaignId = null,
        public ?string $channel = null,
        public ?string $paymentId = null,
        public ?int $percent = null,
        public ?int $amount = null,
        public ?int $maxAmount = null,
        public ?string $createdBy = null,
        public ?string $comment = null,
    ) {}
}
