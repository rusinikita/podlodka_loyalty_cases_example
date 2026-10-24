<?php

declare(strict_types=1);

namespace App\Discounts\Exceptions;

use RuntimeException;

final class CompensationLimitException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('compensation is above the limits');
    }
}
