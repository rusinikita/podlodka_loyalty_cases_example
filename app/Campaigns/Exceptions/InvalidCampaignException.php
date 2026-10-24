<?php

declare(strict_types=1);

namespace App\Campaigns\Exceptions;

use RuntimeException;

final class InvalidCampaignException extends RuntimeException
{
    public function __construct(string $reason)
    {
        parent::__construct('invalid campaign: '.$reason);
    }
}
