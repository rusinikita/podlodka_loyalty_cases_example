<?php

declare(strict_types=1);

namespace App\Clients\PayoutGateway;

use Illuminate\Http\Client\Factory;

/**
 * Client of payout-gateway (api/payout-gateway.yaml).
 */
final class PayoutGatewayClient
{
    public function __construct(
        private readonly Factory $http,
        private readonly string $baseUrl,
        private readonly string $token,
        private readonly float $timeout,
    ) {}

    /**
     * Creates a payout. A repeated call with the same idempotency key returns
     * the original payout.
     *
     * @throws PayoutGatewayException
     */
    public function pay(PayoutRequest $req): Payout
    {
        $response = $this->http->baseUrl($this->baseUrl)
            ->timeout($this->timeout)
            ->withHeaders(['X-Service-Token' => $this->token])
            ->asJson()
            ->post('/v1/payouts', $req->toArray());

        if ($response->status() === 200) {
            return new Payout(
                payoutId: (string) $response->json('payout_id'),
                status: (string) $response->json('status'),
                cardMask: $response->json('card_mask'),
                createdAt: $response->json('created_at'),
            );
        }

        $code = (string) $response->json('code', '');

        throw match ($code) {
            'no_payout_method' => new NoPayoutMethodException($response->status()),
            'user_blocked' => new UserBlockedException($response->status()),
            default => new PayoutGatewayException($response->status(), $code, (string) $response->json('message', '')),
        };
    }
}
