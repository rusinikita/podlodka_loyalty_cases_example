<?php

declare(strict_types=1);

namespace App\Clients\Antifraud;

enum Verdict: string
{
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Pending = 'pending';
}
