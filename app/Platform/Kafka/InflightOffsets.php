<?php

declare(strict_types=1);

namespace App\Platform\Kafka;

/**
 * In-flight messages of one topic per partition. Messages of a partition are
 * handled by different slots and finish out of order; an offset is committed
 * only when every message before it has been handled.
 */
final class InflightOffsets
{
    /** @var array<int, array<int, int>> */
    private array $queues = [];

    /** @var array<int, array<int, true>> */
    private array $done = [];

    public function fetched(int $partition, int $offset): void
    {
        $this->queues[$partition][] = $offset;
    }

    /**
     * Marks the message as handled. Returns the offset that can be committed,
     * if the oldest in-flight message of the partition is now done.
     */
    public function done(int $partition, int $offset): ?int
    {
        $this->done[$partition][$offset] = true;

        $committed = null;
        $queue = $this->queues[$partition] ?? [];
        while ($queue !== [] && isset($this->done[$partition][$queue[0]])) {
            $committed = array_shift($queue);
            unset($this->done[$partition][$committed]);
        }
        $this->queues[$partition] = $queue;

        return $committed;
    }
}
