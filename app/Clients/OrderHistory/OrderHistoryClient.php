<?php

declare(strict_types=1);

namespace App\Clients\OrderHistory;

use Illuminate\Http\Client\Factory;

/**
 * Client of the order history service (api/order-history.yaml).
 */
final class OrderHistoryClient
{
    public function __construct(
        private readonly Factory $http,
        private readonly string $baseUrl,
        private readonly string $token,
        private readonly float $timeout,
    ) {}

    /**
     * The number of the user's paid and completed orders for all time, of
     * every channel and payment method. An unknown user has none.
     *
     * @throws OrderHistoryException
     */
    public function countOrders(int $userId): int
    {
        $response = $this->http->baseUrl($this->baseUrl)
            ->timeout($this->timeout)
            ->withHeaders(['X-Service-Token' => $this->token])
            ->get("/v1/users/{$userId}/orders/count");

        return match ($response->status()) {
            200 => (int) $response->json('count'),
            404 => 0,
            default => throw new OrderHistoryException(
                $response->status(),
                (string) $response->json('code', ''),
                (string) $response->json('message', ''),
            ),
        };
    }
}
