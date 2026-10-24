<?php

declare(strict_types=1);

namespace App\Clients\Notifications;

final readonly class Push
{
    /**
     * @param  array<string, string>  $params
     */
    public function __construct(
        public int $userId,
        public string $template,
        public array $params = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'user_id' => $this->userId,
            'template' => $this->template,
            'params' => $this->params,
        ], static fn (mixed $v): bool => $v !== []);
    }
}
