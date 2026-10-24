<?php

declare(strict_types=1);

namespace App\Campaigns\Exceptions;

use RuntimeException;

final class CampaignExpiredException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('campaign expired');
    }
}
