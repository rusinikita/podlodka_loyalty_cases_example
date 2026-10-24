<?php

declare(strict_types=1);

namespace App\Campaigns\Exceptions;

final class ActivationsExhaustedException extends IneligibleException
{
    public function __construct()
    {
        parent::__construct('max_activations', 'campaign activations exhausted');
    }
}
