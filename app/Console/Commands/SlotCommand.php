<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Consumers\Handlers;
use App\Platform\Kafka\ConsumerSettings;
use App\Platform\Kafka\Message;
use Illuminate\Console\Command;
use Throwable;

/**
 * Handler process of kafka:consume: reads messages from stdin one per line
 * and writes the outcome of each to file descriptor 3.
 */
final class SlotCommand extends Command
{
    protected $signature = 'kafka:slot {consumer}';

    protected $description = 'Handle messages of a consumer, started by kafka:consume';

    protected $hidden = true;

    public function handle(): int
    {
        $cfg = ConsumerSettings::named(config('loyalty.kafka'), (string) $this->argument('consumer'));
        $handler = $this->laravel->make(Handlers::classOf($cfg->handler));
        $results = fopen('php://fd/3', 'w');
        if ($results === false) {
            $this->error('file descriptor 3 is not open');

            return self::FAILURE;
        }

        while (($line = fgets(STDIN)) !== false) {
            $job = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
            if ($job['not_before'] !== null && $job['not_before'] > microtime(true)) {
                usleep((int) (($job['not_before'] - microtime(true)) * 1_000_000));
            }

            try {
                $handler->handle(new Message(
                    topic: $job['topic'],
                    partition: $job['partition'],
                    offset: $job['offset'],
                    key: $job['key'] !== null ? base64_decode($job['key']) : null,
                    value: base64_decode($job['value']),
                    attempt: $job['attempt'],
                ));
                $error = null;
            } catch (Throwable $e) {
                $error = $e->getMessage() !== '' ? $e->getMessage() : $e::class;
            }

            fwrite($results, json_encode(['error' => $error])."\n");
        }

        return self::SUCCESS;
    }
}
