<?php

declare(strict_types=1);

namespace App\Clients\Antifraud;

use RuntimeException;

/**
 * Error response of the antifraud service.
 */
final class AntifraudException extends RuntimeException
{
    public function __construct(
        public readonly int $statusCode,
        public readonly string $errorCode,
        string $message,
    ) {
        parent::__construct("antifraud: {$statusCode} {$errorCode}: {$message}");
    }
}
