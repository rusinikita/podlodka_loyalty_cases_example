<?php

declare(strict_types=1);

namespace App\Campaigns;

final readonly class SearchFilter
{
    public function __construct(
        public string $query = '',
        public string $trigger = '',
        public ?bool $active = null,
        public int $afterId = 0,
        public int $limit = 50,
    ) {}
}
