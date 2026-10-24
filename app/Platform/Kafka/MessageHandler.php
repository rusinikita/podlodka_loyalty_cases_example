<?php

declare(strict_types=1);

namespace App\Platform\Kafka;

/**
 * Processes one message. Returning commits the offset. An exception, as well
 * as a handler that does not return before the processing deadline, sends
 * the message to the retry topic, and after the last attempt to the DLQ.
 */
interface MessageHandler
{
    public function handle(Message $message): void;
}
