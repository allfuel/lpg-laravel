<?php

return [
    'host' => env('LPG_HOST', env('DB_HOST', '127.0.0.1')),
    'port' => env('LPG_PORT', env('DB_PORT', 5432)),

    'data_dir' => env('LPG_DATA_DIR', storage_path('pgdata')),
    'log' => env('LPG_LOG', storage_path('logs/postgres.log')),
    'storage_dir' => env('LPG_STORAGE_DIR', storage_path('pg')),

    'database' => env('LPG_DATABASE', env('DB_DATABASE', 'postgres')),
    'username' => env('LPG_USERNAME', env('DB_USERNAME', 'postgres')),
    'password' => env('LPG_PASSWORD', env('DB_PASSWORD', '')),

    'version' => env('LPG_PG_VERSION', '18.1-pgvector0.8.1'),
    'platform' => env('LPG_PLATFORM', ''),
    'use_system' => filter_var(env('LPG_USE_SYSTEM', false), FILTER_VALIDATE_BOOLEAN),
    'stop_timeout' => (int) env('LPG_STOP_TIMEOUT', 10),

    'embedded' => [
        'source' => env('LPG_EMBEDDED_SOURCE', 'github'),
        'repo' => env('LPG_EMBEDDED_REPO', 'allfuel/lpg'),
        'tag_prefix' => env('LPG_EMBEDDED_TAG_PREFIX', 'v'),
        'asset' => env('LPG_EMBEDDED_ASSET', ''),
        'assets' => [
            'darwin-arm64v8' => env('LPG_EMBEDDED_ASSET_DARWIN_ARM64V8', 'postgres-darwin-arm_64.txz'),
            'darwin-amd64' => env('LPG_EMBEDDED_ASSET_DARWIN_AMD64', 'postgres-darwin-amd_64.txz'),
            'linux-amd64' => env('LPG_EMBEDDED_ASSET_LINUX_AMD64', 'postgres-linux-amd_64.txz'),
            'linux-arm64v8' => env('LPG_EMBEDDED_ASSET_LINUX_ARM64V8', 'postgres-linux-arm_64.txz'),
            'windows-amd64' => env('LPG_EMBEDDED_ASSET_WINDOWS_AMD64', 'postgres-windows-amd_64.txz'),
            'windows-arm64v8' => env('LPG_EMBEDDED_ASSET_WINDOWS_ARM64V8', ''),
        ],
    ],
];
