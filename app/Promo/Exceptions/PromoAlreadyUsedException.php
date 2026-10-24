<?php

declare(strict_types=1);

namespace App\Promo\Exceptions;

final class PromoAlreadyUsedException extends PromoException
{
    public function __construct()
    {
        parent::__construct('promo already used');
    }
}
