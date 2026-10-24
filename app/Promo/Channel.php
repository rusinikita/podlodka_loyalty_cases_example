<?php

declare(strict_types=1);

namespace App\Promo;

/**
 * How the code was entered: from a deeplink shnyr://promo?code=... or typed
 * by the user.
 */
enum Channel: string
{
    case Deeplink = 'deeplink';
    case Manual = 'manual';
}
