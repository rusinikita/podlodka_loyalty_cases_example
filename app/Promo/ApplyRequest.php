<?php

declare(strict_types=1);

namespace App\Promo;

final readonly class ApplyRequest
{
    public function __construct(
        public int $userId,
        public string $country,
        public int $cityId,
        public string $segment,
        public string $code,
        public Channel $channel,
    ) {}
}
