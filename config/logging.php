<?php

use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\StreamHandler;

return [

    'default' => 'stdout',

    'channels' => [
        'stdout' => [
            'driver' => 'monolog',
            'handler' => StreamHandler::class,
            'with' => ['stream' => 'php://stdout'],
            'formatter' => JsonFormatter::class,
            'level' => 'info',
        ],
    ],

];
