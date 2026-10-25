<?php

declare(strict_types=1);

namespace App\Clients\Antifraud;

use DateTimeImmutable;

final readonly class RewardVerdict
{
    public function __construct(
        public int $rewardId,
        public Verdict $verdict,
        public ?string $reason = null,
        public ?DateTimeImmutable $checkedAt = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            rewardId: (int) $data['reward_id'],
            verdict: Verdict::from((string) $data['verdict']),
            reason: isset($data['reason']) ? (string) $data['reason'] : null,
            checkedAt: isset($data['checked_at']) ? new DateTimeImmutable((string) $data['checked_at']) : null,
        );
    }
}
