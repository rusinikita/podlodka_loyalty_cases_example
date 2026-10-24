<?php

declare(strict_types=1);

namespace App\Referrals;

/**
 * State of a referral reward, stored in referral_rewards.status.
 */
enum RewardStatus: int
{
    case Pending = 1;
    case Paid = 2;

    /**
     * The API name of a stored status: "pending", "paid".
     */
    public static function nameOf(int $status): string
    {
        return self::tryFrom($status)?->label() ?? "RewardStatus({$status})";
    }

    public function label(): string
    {
        return strtolower($this->name);
    }
}
