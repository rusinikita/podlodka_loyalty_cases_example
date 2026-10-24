<?php

declare(strict_types=1);

namespace App\Consumers;

use App\Platform\Kafka\MessageHandler;
use InvalidArgumentException;

/**
 * Handlers by the handler name in config/consumers.yaml.
 */
final class Handlers
{
    private const MAP = [
        'payment_succeeded' => PaymentSucceededHandler::class,
        'user_registered' => UserRegisteredHandler::class,
    ];

    /**
     * @return class-string<MessageHandler>
     */
    public static function classOf(string $handler): string
    {
        return self::MAP[$handler] ?? throw new InvalidArgumentException("unknown handler \"{$handler}\"");
    }
}
