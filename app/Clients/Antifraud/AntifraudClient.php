<?php

declare(strict_types=1);

namespace App\Clients\Antifraud;

use Illuminate\Http\Client\Factory;
use InvalidArgumentException;
use UnexpectedValueException;

/**
 * Client of the antifraud verdicts API (api/antifraud.yaml).
 */
final class AntifraudClient
{
    /**
     * The largest number of rewards accepted in one request.
     */
    public const MAX_BATCH = 500;

    private const SOURCE = 'loyalty';

    public function __construct(
        private readonly Factory $http,
        private readonly string $baseUrl,
        private readonly string $token,
        private readonly float $timeout,
    ) {}

    /**
     * Verdicts for up to MAX_BATCH rewards, in the order of the request.
     * A reward antifraud has not seen yet gets Verdict::Pending and a check
     * is started for it.
     *
     * @param  list<RewardCheck>  $items
     * @return list<RewardVerdict>
     *
     * @throws AntifraudException
     */
    public function verdicts(array $items): array
    {
        if ($items === []) {
            return [];
        }
        if (count($items) > self::MAX_BATCH) {
            throw new InvalidArgumentException(sprintf(
                'antifraud: batch of %d rewards, at most %d allowed', count($items), self::MAX_BATCH,
            ));
        }

        $response = $this->http->baseUrl($this->baseUrl)
            ->timeout($this->timeout)
            ->withHeaders(['X-Service-Token' => $this->token])
            ->asJson()
            ->post('/v1/referral-rewards/verdicts', [
                'source' => self::SOURCE,
                'items' => array_map(static fn (RewardCheck $c): array => $c->toArray(), $items),
            ]);

        if ($response->status() !== 200) {
            throw new AntifraudException(
                $response->status(),
                (string) $response->json('code', ''),
                (string) $response->json('message', ''),
            );
        }

        /** @var list<array<string, mixed>> $rows */
        $rows = $response->json('items', []);
        if (count($rows) !== count($items)) {
            throw new UnexpectedValueException(sprintf(
                'antifraud: got %d verdicts for %d rewards', count($rows), count($items),
            ));
        }

        return array_map(RewardVerdict::fromArray(...), $rows);
    }
}
