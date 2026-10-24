<?php

declare(strict_types=1);

namespace App\Platform\Kafka;

use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\Log;
use RdKafka\Conf;
use RdKafka\Message as KafkaMessage;
use RdKafka\Producer;
use RuntimeException;

/**
 * Consumer runtime required by the platform: commit after handle, per-key
 * ordering, a processing deadline per message, a retry topic with backoff and
 * a DLQ after the last attempt.
 *
 * The consumer reads the main and the retry topic and hands messages to
 * `concurrency` handler processes (slots). Messages with the same key always
 * go to the same slot, so they are handled in order. A slot that does not
 * finish a message before the deadline is killed and started again.
 */
final class PlatformConsumer
{
    private const HEADER_ATTEMPT = 'x-attempt';

    private const HEADER_NOT_BEFORE = 'x-not-before';

    private const HEADER_ERROR = 'x-error';

    private const FLUSH_TIMEOUT_MS = 10_000;

    /** @var array<int, Reader> */
    private array $readers = [];

    /** @var array<int, Slot> */
    private array $slots = [];

    private readonly Producer $producer;

    private ?string $deliveryError = null;

    /**
     * @param  list<string>  $slotCommand  command line of a handler process
     */
    public function __construct(
        private readonly ConsumerSettings $cfg,
        private readonly array $slotCommand,
    ) {
        $conf = new Conf;
        $conf->set('metadata.broker.list', implode(',', $cfg->brokers));
        $conf->set('acks', 'all');
        $conf->setDrMsgCb(function (Producer $producer, KafkaMessage $message): void {
            if ($message->err !== RD_KAFKA_RESP_ERR_NO_ERROR) {
                $this->deliveryError = $message->errstr();
            }
        });
        $this->producer = new Producer($conf);
    }

    public function start(): void
    {
        for ($i = 0; $i < $this->cfg->concurrency; $i++) {
            $this->slots[] = new Slot($this->slotCommand);
        }
        $this->readers[] = new Reader($this->cfg, $this->cfg->topic, $this->cfg->group);
        $this->readers[] = new Reader($this->cfg, $this->cfg->retryTopic, $this->cfg->group.'-retry');
    }

    /**
     * One round of the loop: fetch, hand over, collect outcomes, commit.
     */
    public function tick(float $wait): void
    {
        foreach ($this->readers as $reader) {
            $this->dispatch($reader);
        }
        $this->collect($wait);
        $this->expire();
        foreach ($this->readers as $reader) {
            $reader->commit();
        }
    }

    /**
     * Stops the handlers. Messages in flight stay uncommitted and are
     * redelivered after restart.
     */
    public function stop(): void
    {
        foreach ($this->slots as $slot) {
            $slot->close();
        }
        foreach ($this->readers as $reader) {
            $reader->close();
        }
        $this->producer->flush(self::FLUSH_TIMEOUT_MS);
    }

    private function dispatch(Reader $reader): void
    {
        $message = $reader->waiting ?? $reader->fetch();
        if ($message === null) {
            return;
        }

        $slot = $this->slots[self::slotOf($message->key, count($this->slots))];
        if ($slot->busy()) {
            $reader->waiting = $message;

            return;
        }
        $reader->waiting = null;

        $headers = (array) $message->headers;
        $slot->send($reader, $message, self::attemptOf($headers), self::notBeforeOf($headers), $this->cfg->processingTimeout);
    }

    private function collect(float $wait): void
    {
        $streams = [];
        foreach ($this->slots as $i => $slot) {
            if ($slot->busy()) {
                $streams[$i] = $slot->results();
            }
        }
        if ($streams === []) {
            usleep((int) ($wait * 1_000_000));

            return;
        }

        $write = $except = null;
        if (stream_select($streams, $write, $except, 0, (int) ($wait * 1_000_000)) === false) {
            return;
        }
        foreach (array_keys($streams) as $i) {
            $slot = $this->slots[$i];
            $job = $slot->job();
            if ($job !== null) {
                $this->finish($job, $slot->finish());
            }
        }
    }

    private function expire(): void
    {
        $now = microtime(true);
        foreach ($this->slots as $slot) {
            $job = $slot->job();
            if ($job !== null && $now > $job->deadline) {
                $slot->restart();
                $this->finish($job, 'processing deadline exceeded');
            }
        }
    }

    private function finish(InFlight $job, ?string $error): void
    {
        if ($error !== null) {
            $this->forward($job, $error);
        }
        $job->reader->done($job->message);
    }

    private function forward(InFlight $job, string $error): void
    {
        $m = $job->message;
        $context = [
            'consumer' => $this->cfg->name,
            'topic' => $m->topic_name,
            'partition' => $m->partition,
            'offset' => $m->offset,
            'attempt' => $job->attempt,
            'err' => $error,
        ];

        if ($job->attempt >= $this->cfg->maxAttempts) {
            Log::error('message sent to dlq', $context);
            $this->produce($this->cfg->dlqTopic, $m, [
                self::HEADER_ATTEMPT => (string) $job->attempt,
                self::HEADER_ERROR => $error,
            ]);

            return;
        }

        Log::warning('message sent to retry', $context);
        $notBefore = (new DateTimeImmutable)->modify(sprintf('+%d milliseconds', (int) ($this->backoff($job->attempt) * 1000)));
        $this->produce($this->cfg->retryTopic, $m, [
            self::HEADER_ATTEMPT => (string) ($job->attempt + 1),
            self::HEADER_ERROR => $error,
            self::HEADER_NOT_BEFORE => $notBefore->format(DateTimeInterface::RFC3339_EXTENDED),
        ]);
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function produce(string $topic, KafkaMessage $m, array $headers): void
    {
        $this->deliveryError = null;
        $this->producer->newTopic($topic)->producev(RD_KAFKA_PARTITION_UA, 0, $m->payload, $m->key, $headers);

        $result = $this->producer->flush(self::FLUSH_TIMEOUT_MS);
        $error = $this->deliveryError();
        if ($result !== RD_KAFKA_RESP_ERR_NO_ERROR || $error !== null) {
            throw new RuntimeException("write to {$topic}: ".($error ?? rd_kafka_err2str($result)));
        }
    }

    // Set by the delivery report callback during flush.
    private function deliveryError(): ?string
    {
        return $this->deliveryError;
    }

    private function backoff(int $attempt): float
    {
        $backoff = $this->cfg->backoff;
        if ($backoff === []) {
            return 0.0;
        }

        return $backoff[min($attempt - 1, count($backoff) - 1)];
    }

    private static function slotOf(?string $key, int $slots): int
    {
        return (int) (hexdec(hash('fnv1a32', $key ?? '')) % $slots);
    }

    /**
     * @param  array<string, string>  $headers
     */
    private static function attemptOf(array $headers): int
    {
        $attempt = (int) ($headers[self::HEADER_ATTEMPT] ?? 1);

        return $attempt > 0 ? $attempt : 1;
    }

    /**
     * @param  array<string, string>  $headers
     */
    private static function notBeforeOf(array $headers): ?float
    {
        if (! isset($headers[self::HEADER_NOT_BEFORE])) {
            return null;
        }
        $t = DateTimeImmutable::createFromFormat(DateTimeInterface::RFC3339_EXTENDED, $headers[self::HEADER_NOT_BEFORE]);

        return $t !== false ? (float) $t->format('U.u') : null;
    }
}
