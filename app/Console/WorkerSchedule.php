<?php

declare(strict_types=1);

namespace App\Console;

use Illuminate\Console\Scheduling\Event;
use InvalidArgumentException;

/**
 * Worker from config/workers.yaml.
 */
final readonly class WorkerSchedule
{
    public function __construct(
        public string $name,
        public string $schedule,
        public bool $runOnStart,
        public int $batchSize,
    ) {}

    /**
     * @return array<int, self>
     */
    public static function all(): array
    {
        return array_map(static fn (array $w): self => new self(
            name: (string) $w['name'],
            schedule: (string) $w['schedule'],
            runOnStart: (bool) ($w['run_on_start'] ?? false),
            batchSize: (int) ($w['batch_size'] ?? 0),
        ), config('loyalty.workers'));
    }

    /**
     * Applies a schedule of the form "every <N>s", "every <N>m" or
     * "every <N>h" to the scheduler event. Seconds are limited to the
     * intervals the scheduler can repeat within a minute.
     */
    public function applyTo(Event $event): Event
    {
        if (! preg_match('/^every (\d+)([smh])$/', $this->schedule, $m)) {
            throw new InvalidArgumentException("worker {$this->name}: unsupported schedule \"{$this->schedule}\"");
        }
        $n = (int) $m[1];

        return match ($m[2]) {
            'h' => $event->cron("0 */{$n} * * *"),
            'm' => $event->cron("*/{$n} * * * *"),
            's' => match ($n) {
                1 => $event->everySecond(),
                2 => $event->everyTwoSeconds(),
                5 => $event->everyFiveSeconds(),
                10 => $event->everyTenSeconds(),
                15 => $event->everyFifteenSeconds(),
                20 => $event->everyTwentySeconds(),
                30 => $event->everyThirtySeconds(),
                default => throw new InvalidArgumentException("worker {$this->name}: unsupported schedule \"{$this->schedule}\""),
            },
        };
    }
}
