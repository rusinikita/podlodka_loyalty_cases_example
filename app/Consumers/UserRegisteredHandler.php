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
 * Runs the user_registered campaigns for a new user. Payload of
 * users.user-registered: api/events/user-registered.yaml.
 */
final class UserRegisteredHandler implements MessageHandler
{
    public function __construct(
        private readonly CampaignService $campaigns,
    ) {}

    public function handle(Message $message): void
    {
        try {
            $evt = json_decode($message->value, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            Log::error('malformed registration event', [
                'partition' => $message->partition,
                'offset' => $message->offset,
                'err' => $e->getMessage(),
            ]);

            return;
        }

        $userId = (int) ($evt['user_id'] ?? 0);
        if ($userId === 0) {
            Log::error('incomplete registration event', [
                'event_id' => $evt['event_id'] ?? null,
                'partition' => $message->partition,
                'offset' => $message->offset,
            ]);

            return;
        }

        $this->campaigns->handle(new Event(
            trigger: Trigger::UserRegistered,
            userId: $userId,
            country: (string) ($evt['country'] ?? ''),
            cityId: (int) ($evt['city_id'] ?? 0),
            invitedBy: isset($evt['invited_by']) ? (int) $evt['invited_by'] : null,
        ));
    }
}
