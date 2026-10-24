<?php

declare(strict_types=1);

namespace App\Platform\Kafka;

/**
 * One Kafka record passed to a handler.
 */
final readonly class Message
{
    public function __construct(
        public string $topic,
        public int $partition,
        public int $offset,
        public ?string $key,
        public string $value,
        // 1 for the first delivery, grows with every retry.
        public int $attempt,
    ) {}
}
