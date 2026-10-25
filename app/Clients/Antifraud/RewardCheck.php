<?php

declare(strict_types=1);

namespace App\Clients\Antifraud;

use DateTimeImmutable;
use DateTimeInterface;

final readonly class RewardCheck
{
    public function __construct(
        public int $rewardId,
        public int $inviterId,
        public int $inviteeId,
        public int $amount,
        public string $currency,
        public DateTimeImmutable $createdAt,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'reward_id' => $this->rewardId,
            'inviter_id' => $this->inviterId,
            'invitee_id' => $this->inviteeId,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'created_at' => $this->createdAt->format(DateTimeInterface::RFC3339_EXTENDED),
        ];
    }
}
