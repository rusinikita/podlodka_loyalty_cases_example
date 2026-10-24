<?php

declare(strict_types=1);

namespace App\Consumers;

use App\Campaigns\CampaignService;
use App\Campaigns\Event;
use App\Campaigns\Trigger;
use App\Platform\Kafka\Message;
use App\Platform\Kafka\MessageHandler;
use Illuminate\Support\Facades\Log;
use JsonException;

/**
 * Runs the payment_succeeded campaigns for a paid order. Payload of
 * payments.payment-succeeded: api/events/payment-succeeded.yaml.
 */
final class PaymentSucceededHandler implements MessageHandler
{
    public function __construct(
        private readonly CampaignService $campaigns,
    ) {}

    public function handle(Message $message): void
    {
        try {
            $evt = json_decode($message->value, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            // Redelivery will not fix a broken payload.
            Log::error('malformed payment event', [
                'partition' => $message->partition,
                'offset' => $message->offset,
                'err' => $e->getMessage(),
            ]);

            return;
        }

        $userId = (int) ($evt['user_id'] ?? 0);
        $paymentId = (string) ($evt['payment_id'] ?? '');
        if ($userId === 0 || $paymentId === '') {
            Log::error('incomplete payment event', [
                'event_id' => $evt['event_id'] ?? null,
                'partition' => $message->partition,
                'offset' => $message->offset,
            ]);

            return;
        }

        $this->campaigns->handle(new Event(
            trigger: Trigger::PaymentSucceeded,
            userId: $userId,
            country: (string) ($evt['country'] ?? ''),
            cityId: (int) ($evt['city_id'] ?? 0),
            segment: (string) ($evt['user_segment'] ?? ''),
            paymentId: $paymentId,
            paymentMethod: (string) ($evt['payment_method'] ?? ''),
            skuIds: array_map(static fn (array $item): int => (int) $item['sku_id'], $evt['items'] ?? []),
        ));
    }
}
