<?php

declare(strict_types=1);

namespace App\Campaigns\Exceptions;

final class AlreadyUsedException extends IneligibleException
{
    public function __construct()
    {
        parent::__construct('per_user_limit', 'per-user limit reached');
    }
}
