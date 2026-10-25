<?php

use App\Console\WorkerSchedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

// Commands of the workers in config/workers.yaml.
$commands = [
    'reward-payouts' => 'rewards:pay',
];

foreach (WorkerSchedule::all() as $worker) {
    $command = $commands[$worker->name] ?? throw new InvalidArgumentException("unknown worker \"{$worker->name}\"");

    $worker->applyTo(Schedule::command($command, ['--batch-size' => $worker->batchSize]))
        ->name($worker->name)
        ->withoutOverlapping();
}

Artisan::command('workers:start', function () use ($commands) {
    foreach (WorkerSchedule::all() as $worker) {
        if ($worker->runOnStart) {
            $this->call($commands[$worker->name], ['--batch-size' => $worker->batchSize]);
        }
    }

    // schedule:work starts on the next minute; cover the current one.
    $this->call('schedule:run');

    return $this->call('schedule:work');
})->purpose('Run the workers marked run_on_start, then the scheduler');
