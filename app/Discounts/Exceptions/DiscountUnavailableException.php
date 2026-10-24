<?php

declare(strict_types=1);

namespace App\Discounts\Exceptions;

use RuntimeException;

final class DiscountUnavailableException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('discount is used or expired');
    }
}
