<?php

use App\Support\YamlConfig;

$database = YamlConfig::load('app.yaml', expandEnv: true)['database'];

return [

    'default' => 'pgsql',

    'connections' => [
        'pgsql' => [
            'driver' => 'pgsql',
            'url' => $database['dsn'],
            'charset' => 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => 'prefer',
        ],
    ],

];
