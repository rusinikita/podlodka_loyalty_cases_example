<?php

declare(strict_types=1);

namespace App\Discounts\Exceptions;

use RuntimeException;

final class CommentRequiredException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('comment with the ticket number is required');
    }
}
