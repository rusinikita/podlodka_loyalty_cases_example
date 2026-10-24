<?php

use App\Support\YamlConfig;

// Settings of the service: app.yaml, consumers.yaml and workers.yaml next to
// this file. ${VAR} references in app.yaml are replaced with environment
// variables; ${PLATFORM_DEFAULT} in consumers.yaml is resolved by the
// consumer runtime.
return YamlConfig::load('app.yaml', expandEnv: true) + [
    'kafka' => YamlConfig::load('consumers.yaml'),
    'workers' => YamlConfig::load('workers.yaml')['workers'] ?? [],
];
