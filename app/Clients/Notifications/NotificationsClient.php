<?php

declare(strict_types=1);

namespace App\Clients\Notifications;

use Illuminate\Http\Client\Factory;
use RuntimeException;

/**
 * Client of the CRM notifications service (api/notifications.yaml).
 */
final class NotificationsClient
{
    public function __construct(
        private readonly Factory $http,
        private readonly string $baseUrl,
        private readonly string $token,
        private readonly float $timeout,
    ) {}

    public function push(Push $push): void
    {
        $response = $this->http->baseUrl($this->baseUrl)
            ->timeout($this->timeout)
            ->withHeaders(['X-Service-Token' => $this->token])
            ->asJson()
            ->post('/v1/push', $push->toArray());

        if ($response->status() !== 202) {
            throw new RuntimeException("notifications: unexpected status {$response->status()}");
        }
    }
}
