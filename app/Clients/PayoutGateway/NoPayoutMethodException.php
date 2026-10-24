<?php

declare(strict_types=1);

namespace App\Clients\PayoutGateway;

/**
 * The user has no card linked in payout-gateway.
 */
final class NoPayoutMethodException extends PayoutGatewayException
{
    public function __construct(int $status)
    {
        parent::__construct($status, 'no_payout_method', 'no payout method');
    }
}
