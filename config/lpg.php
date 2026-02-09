<?php

return [
    'host' => env('DB_HOST', '127.0.0.1'),
    'port' => env('DB_PORT', 5432),

    'data_dir' => storage_path('pgdata'),
    'log' => storage_path('logs/postgres.log'),
    'storage_dir' => storage_path('pg'),

    'database' => env('DB_DATABASE', 'postgres'),
    'username' => env('DB_USERNAME', 'postgres'),
    'password' => env('DB_PASSWORD', ''),

    'version' => '18.1-pgvector0.8.1',
    'platform' => '',
    'stop_timeout' => 10,

    'embedded' => [
        'source' => 'github',
        'repo' => 'allfuel/lpg',
        'tag_prefix' => 'v',
        'asset' => '',
        'assets' => [
            'darwin-arm64v8' => 'postgres-darwin-arm_64.txz',
            'darwin-amd64' => 'postgres-darwin-amd_64.txz',
            'linux-amd64' => 'postgres-linux-amd_64.txz',
            'linux-arm64v8' => 'postgres-linux-arm_64.txz',
            'windows-amd64' => 'postgres-windows-amd_64.txz',
            'windows-arm64v8' => '',
        ],
    ],
];
