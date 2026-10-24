<?php

declare(strict_types=1);

namespace App\Campaigns\Exceptions;

use RuntimeException;

/**
 * A grant condition of the campaign does not match the event. The reason
 * tells which one.
 */
class IneligibleException extends RuntimeException
{
    public function __construct(
        public readonly string $reason,
        string $message = 'not eligible',
    ) {
        parent::__construct($message.': '.$reason);
    }
}
