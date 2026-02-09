<?php

it('defines known embedded asset mappings', function (): void {
    $config = require __DIR__.'/../../config/lpg.php';

    expect($config['embedded']['assets'])->toHaveKeys([
        'darwin-arm64v8',
        'darwin-amd64',
        'linux-amd64',
        'linux-arm64v8',
        'windows-amd64',
        'windows-arm64v8',
    ]);
});

it('defaults to the tar.gz embedded release version', function (): void {
    $config = require __DIR__.'/../../config/lpg.php';

    expect($config['version'])->toBe('18.1-pgvector0.8.1-targz');
});
