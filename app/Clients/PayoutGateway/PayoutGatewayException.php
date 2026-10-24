<?php

declare(strict_types=1);

namespace App\Clients\PayoutGateway;

use RuntimeException;

/**
 * Error response of payout-gateway.
 */
class PayoutGatewayException extends RuntimeException
{
    public function __construct(
        public readonly int $statusCode,
        public readonly string $errorCode,
        string $message,
    ) {
        parent::__construct("payout-gateway: {$statusCode} {$errorCode}: {$message}");
    }
}
