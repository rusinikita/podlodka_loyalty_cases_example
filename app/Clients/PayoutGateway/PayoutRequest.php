<?php

declare(strict_types=1);

namespace App\Clients\PayoutGateway;

final readonly class PayoutRequest
{
    public function __construct(
        public string $idempotencyKey,
        public int $userId,
        public int $amount,
        public string $currency,
        public string $purpose,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'idempotency_key' => $this->idempotencyKey,
            'user_id' => $this->userId,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'purpose' => $this->purpose,
        ];
    }
}
