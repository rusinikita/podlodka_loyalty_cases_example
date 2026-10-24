<?php

declare(strict_types=1);

namespace App\Platform\Kafka;

use RdKafka\Conf;
use RdKafka\KafkaConsumer;
use RdKafka\Message as KafkaMessage;
use RdKafka\TopicPartition;
use RuntimeException;

/**
 * Reads one topic in its consumer group and commits handled offsets.
 */
final class Reader
{
    private readonly KafkaConsumer $consumer;

    private readonly InflightOffsets $inflight;

    private readonly float $commitInterval;

    // A fetched message whose slot is busy. The reader does not fetch more
    // until it is handed over.
    public ?KafkaMessage $waiting = null;

    /** @var array<int, int> next offset to commit by partition */
    private array $commits = [];

    private float $committedAt = 0.0;

    public function __construct(
        ConsumerSettings $cfg,
        public readonly string $topic,
        string $group,
    ) {
        $conf = new Conf;
        $conf->set('metadata.broker.list', implode(',', $cfg->brokers));
        $conf->set('group.id', $group);
        $conf->set('enable.auto.commit', 'false');
        $conf->set('auto.offset.reset', $cfg->autoOffsetReset);
        $conf->set('session.timeout.ms', (string) (int) ($cfg->sessionTimeout * 1000));

        $this->consumer = new KafkaConsumer($conf);
        $this->consumer->subscribe([$topic]);
        $this->inflight = new InflightOffsets;
        $this->commitInterval = $cfg->commitInterval;
    }

    /**
     * Returns the next message, or null if there is none right now.
     */
    public function fetch(): ?KafkaMessage
    {
        $message = $this->consumer->consume(0);

        switch ($message->err) {
            case RD_KAFKA_RESP_ERR_NO_ERROR:
                $this->inflight->fetched($message->partition, $message->offset);

                return $message;
            case RD_KAFKA_RESP_ERR__TIMED_OUT:
            case RD_KAFKA_RESP_ERR__PARTITION_EOF:
                return null;
            default:
                throw new RuntimeException("fetch {$this->topic}: {$message->errstr()}");
        }
    }

    public function done(KafkaMessage $message): void
    {
        $committed = $this->inflight->done($message->partition, $message->offset);
        if ($committed !== null) {
            $this->commits[$message->partition] = $committed + 1;
        }
    }

    public function commit(bool $force = false): void
    {
        if ($this->commits === [] || (! $force && microtime(true) - $this->committedAt < $this->commitInterval)) {
            return;
        }

        $offsets = [];
        foreach ($this->commits as $partition => $offset) {
            $offsets[] = new TopicPartition($this->topic, $partition, $offset);
        }
        $force ? $this->consumer->commit($offsets) : $this->consumer->commitAsync($offsets);
        $this->commits = [];
        $this->committedAt = microtime(true);
    }

    public function close(): void
    {
        $this->commit(force: true);
        $this->consumer->close();
    }
}
