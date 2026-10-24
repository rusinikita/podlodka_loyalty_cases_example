<?php

declare(strict_types=1);

namespace App\Clients\PayoutGateway;

final class UserBlockedException extends PayoutGatewayException
{
    public function __construct(int $status)
    {
        parent::__construct($status, 'user_blocked', 'user blocked');
    }
}
