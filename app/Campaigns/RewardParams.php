<?php

declare(strict_types=1);

namespace App\Campaigns;

/**
 * Reward of a campaign. A discount has percent with an optional max_amount
 * cap, or a fixed amount; ttl_days, or until the end of the campaign if not
 * set. A referral reward has amount and recipient.
 */
final readonly class RewardParams
{
    public const RECIPIENT_INVITER = 'inviter';

    public function __construct(
        public int $percent = 0,
        public int $amount = 0,
        public int $maxAmount = 0,
        public int $ttlDays = 0,
        public ?string $recipient = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            percent: (int) ($data['percent'] ?? 0),
            amount: (int) ($data['amount'] ?? 0),
            maxAmount: (int) ($data['max_amount'] ?? 0),
            ttlDays: (int) ($data['ttl_days'] ?? 0),
            recipient: isset($data['recipient']) ? (string) $data['recipient'] : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'percent' => $this->percent,
            'amount' => $this->amount,
            'max_amount' => $this->maxAmount,
            'ttl_days' => $this->ttlDays,
            'recipient' => $this->recipient,
        ], static fn (mixed $v): bool => $v !== null && $v !== 0 && $v !== '');
    }
}
