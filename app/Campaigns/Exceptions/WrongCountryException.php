<?php

declare(strict_types=1);

namespace App\Campaigns\Exceptions;

/**
 * The campaign is not available in the user's country.
 */
final class WrongCountryException extends IneligibleException
{
    public function __construct()
    {
        parent::__construct('country', 'campaign is not available in the user\'s country');
    }
}
