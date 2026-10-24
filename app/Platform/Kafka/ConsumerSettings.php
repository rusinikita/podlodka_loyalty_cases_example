<?php

declare(strict_types=1);

namespace App\Platform\Kafka;

use App\Support\Duration;
use InvalidArgumentException;

/**
 * Consumer from config/consumers.yaml with defaults applied and platform
 * values resolved.
 */
final readonly class ConsumerSettings
{
    // A value that the platform injects into the pod environment. It is the
    // same for every service of the cluster.
    private const PLATFORM_DEFAULT = '${PLATFORM_DEFAULT}';

    private const ENV_PROCESSING_TIMEOUT = 'PLATFORM_CONSUMER_PROCESSING_TIMEOUT';

    private const ENV_RETRY_BACKOFF = 'PLATFORM_CONSUMER_RETRY_BACKOFF';

    /**
     * @param  array<int, string>  $brokers
     * @param  array<int, float>  $backoff  seconds
     */
    public function __construct(
        public string $name,
        public string $handler,
        public array $brokers,
        public string $topic,
        public string $group,
        public string $retryTopic,
        public string $dlqTopic,
        public string $autoOffsetReset,
        public float $sessionTimeout,
        public float $commitInterval,
        public int $concurrency,
        public float $processingTimeout,
        public int $maxAttempts,
        public array $backoff,
    ) {}

    /**
     * @param  array<string, mixed>  $file  parsed consumers.yaml
     * @return array<int, self>
     */
    public static function all(array $file): array
    {
        return array_map(static fn (array $c): self => self::effective($file, $c), $file['consumers'] ?? []);
    }

    /**
     * @param  array<string, mixed>  $file  parsed consumers.yaml
     */
    public static function named(array $file, string $name): self
    {
        foreach (self::all($file) as $consumer) {
            if ($consumer->name === $name) {
                return $consumer;
            }
        }

        throw new InvalidArgumentException("unknown consumer \"{$name}\"");
    }

    /**
     * @param  array<string, mixed>  $file
     * @param  array<string, mixed>  $c
     */
    private static function effective(array $file, array $c): self
    {
        $d = $file['defaults'] ?? [];
        $name = (string) $c['name'];

        $timeout = self::resolve($c['processing']['processing_timeout'] ?? $d['processing']['processing_timeout'] ?? '',
            self::ENV_PROCESSING_TIMEOUT, "consumer {$name}: processing_timeout");
        $backoff = self::resolve($c['retry']['backoff'] ?? $d['retry']['backoff'] ?? '',
            self::ENV_RETRY_BACKOFF, "consumer {$name}: retry.backoff");

        return new self(
            name: $name,
            handler: (string) $c['handler'],
            brokers: self::brokers((string) ($file['kafka']['cluster'] ?? '')),
            topic: (string) $c['topic'],
            group: (string) $c['group'],
            retryTopic: (string) $c['retry']['topic'],
            dlqTopic: (string) $c['retry']['dlq'],
            autoOffsetReset: (string) ($d['auto_offset_reset'] ?? 'latest'),
            sessionTimeout: Duration::seconds((string) ($d['session_timeout'] ?? '30s')),
            commitInterval: Duration::seconds((string) ($d['commit']['interval'] ?? '1s')),
            concurrency: (int) ($c['processing']['concurrency'] ?? $d['processing']['concurrency'] ?? 1),
            processingTimeout: Duration::seconds($timeout),
            maxAttempts: (int) ($c['retry']['max_attempts'] ?? $d['retry']['max_attempts'] ?? 1),
            backoff: Duration::list($backoff),
        );
    }

    /**
     * Broker list of the cluster, e.g. KAFKA_MAIN_EVENTS_BROKERS for cluster
     * main-events.
     *
     * @return array<int, string>
     */
    private static function brokers(string $cluster): array
    {
        $env = 'KAFKA_'.strtoupper(str_replace('-', '_', $cluster)).'_BROKERS';

        return explode(',', (string) getenv($env));
    }

    private static function resolve(string $value, string $env, string $what): string
    {
        if ($value !== self::PLATFORM_DEFAULT) {
            return $value;
        }

        $resolved = getenv($env);
        if ($resolved === false || $resolved === '') {
            throw new InvalidArgumentException("{$what}: {$env} is not set");
        }

        return $resolved;
    }
}
