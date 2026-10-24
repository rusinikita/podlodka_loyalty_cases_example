<?php

declare(strict_types=1);

namespace App\Referrals;

final readonly class NewReward
{
    public function __construct(
        public int $campaignId,
        public int $userId,
        public int $inviteeId,
        public int $amount,
    ) {}
}
