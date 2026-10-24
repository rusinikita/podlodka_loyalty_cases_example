<?php

declare(strict_types=1);

namespace App\Promo\Exceptions;

final class PromoMonthlyLimitException extends PromoException
{
    public function __construct()
    {
        parent::__construct('monthly promo limit reached');
    }
}
