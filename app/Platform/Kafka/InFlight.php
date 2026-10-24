<?php

declare(strict_types=1);

namespace App\Platform\Kafka;

use RdKafka\Message as KafkaMessage;

/**
 * Message handed to a slot, with the time it must be handled by.
 */
final readonly class InFlight
{
    public function __construct(
        public Reader $reader,
        public KafkaMessage $message,
        public int $attempt,
        public float $deadline,
    ) {}
}
