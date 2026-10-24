<?php

declare(strict_types=1);

namespace App\Referrals;

use DateTimeImmutable;
use DateTimeInterface;

/**
 * Row of referral_rewards. userId is the inviter who gets the money,
 * inviteeId is the invited friend.
 */
final readonly class Reward
{
    public function __construct(
        public int $id,
        public int $campaignId,
        public int $userId,
        public int $inviteeId,
        public int $amount,
        public int $status,
        public DateTimeImmutable $statusChangedAt,
        public ?string $payoutId,
        public DateTimeImmutable $createdAt,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'invitee_id' => $this->inviteeId,
            'amount' => $this->amount,
            'status' => RewardStatus::nameOf($this->status),
            'status_changed_at' => $this->statusChangedAt->format(DateTimeInterface::RFC3339_EXTENDED),
            'created_at' => $this->createdAt->format(DateTimeInterface::RFC3339_EXTENDED),
        ];
    }
}
