<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Consumers\Handlers;
use App\Platform\Kafka\ConsumerSettings;
use App\Platform\Kafka\PlatformConsumer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Runs every consumer from config/consumers.yaml until SIGTERM.
 */
final class ConsumeCommand extends Command
{
    protected $signature = 'kafka:consume';

    protected $description = 'Consume the Kafka topics from config/consumers.yaml';

    private const LOOP_WAIT = 0.005;

    private bool $running = true;

    public function handle(): int
    {
        $consumers = [];
        foreach (ConsumerSettings::all(config('loyalty.kafka')) as $cfg) {
            Handlers::classOf($cfg->handler);
            $consumers[] = new PlatformConsumer($cfg, [PHP_BINARY, base_path('artisan'), 'kafka:slot', $cfg->name]);
        }

        $this->trap([SIGTERM, SIGINT], function (): void {
            $this->running = false;
        });

        foreach ($consumers as $consumer) {
            $consumer->start();
        }
        Log::info('consumers started', ['count' => count($consumers)]);

        while ($this->running) {
            foreach ($consumers as $consumer) {
                $consumer->tick(self::LOOP_WAIT);
            }
        }

        foreach ($consumers as $consumer) {
            $consumer->stop();
        }
        Log::info('consumers stopped');

        return self::SUCCESS;
    }
}
