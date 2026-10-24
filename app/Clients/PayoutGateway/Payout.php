<?php

declare(strict_types=1);

namespace App\Clients\PayoutGateway;

final readonly class Payout
{
    public function __construct(
        public string $payoutId,
        public string $status,
        public ?string $cardMask,
        public ?string $createdAt,
    ) {}
}
