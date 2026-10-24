<?php

declare(strict_types=1);

namespace App\Console;

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
     * Cron expression of a schedule of the form "every <N>m" or "every <N>h".
     */
    public function cron(): string
    {
        if (! preg_match('/^every (\d+)([mh])$/', $this->schedule, $m)) {
            throw new InvalidArgumentException("worker {$this->name}: unsupported schedule \"{$this->schedule}\"");
        }

        return $m[2] === 'm' ? "*/{$m[1]} * * * *" : "0 */{$m[1]} * * *";
    }
}
