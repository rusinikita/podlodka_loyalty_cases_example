<?php

declare(strict_types=1);

namespace App\Campaigns\Exceptions;

use RuntimeException;

final class MonthlyLimitException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('monthly promo code limit reached');
    }
}
