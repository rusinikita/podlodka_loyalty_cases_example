<?php

declare(strict_types=1);

namespace App\Campaigns\Exceptions;

use RuntimeException;

final class CampaignConflictException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('slug or promo code already exists');
    }
}
