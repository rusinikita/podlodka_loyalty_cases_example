<?php

declare(strict_types=1);

namespace App\Campaigns;

enum Trigger: string
{
    case PromoCode = 'promo_code';
    case PaymentSucceeded = 'payment_succeeded';
    case UserRegistered = 'user_registered';
}
