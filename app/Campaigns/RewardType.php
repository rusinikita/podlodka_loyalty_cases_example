<?php

declare(strict_types=1);

namespace App\Campaigns;

enum RewardType: string
{
    case Discount = 'discount';
    case Referral = 'referral_reward';
}
