<?php

declare(strict_types=1);

namespace App\Clients\OrderHistory;

use RuntimeException;

/**
 * Error response of the order history service.
 */
final class OrderHistoryException extends RuntimeException
{
    public function __construct(
        public readonly int $statusCode,
        public readonly string $errorCode,
        string $message,
    ) {
        parent::__construct("order-history: {$statusCode} {$errorCode}: {$message}");
    }
}
