<?php

declare(strict_types=1);

namespace App\Promo\Exceptions;

final class PromoWrongCountryException extends PromoException
{
    public function __construct()
    {
        parent::__construct('promo not available in the user\'s country');
    }
}
