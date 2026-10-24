<?php

declare(strict_types=1);

namespace App\Promo\Exceptions;

final class PromoLimitReachedException extends PromoException
{
    public function __construct()
    {
        parent::__construct('promo activation limit reached');
    }
}
