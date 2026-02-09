<?php

use Illuminate\Support\Facades\Artisan;

function runLpgExpectingFailure(array $arguments): string
{
    try {
        $exitCode = Artisan::call('lpg', $arguments);
        expect($exitCode)->toBe(1);

        return Artisan::output();
    } catch (\RuntimeException $e) {
        return $e->getMessage();
    }
}

function configureTestStorage(): void
{
    config()->set('lpg.storage_dir', sys_get_temp_dir().'/lpg-tests-'.uniqid('', true));
    config()->set('lpg.data_dir', sys_get_temp_dir().'/lpg-data-'.uniqid('', true));
    config()->set('lpg.log', sys_get_temp_dir().'/lpg-log-'.uniqid('', true).'.log');
}

it('registers the lpg command', function (): void {
    expect(Artisan::all())->toHaveKey('lpg');
});

it('rejects a non-numeric port value', function (): void {
    $message = runLpgExpectingFailure(['--port' => 'abc']);

    expect($message)->toContain('Invalid --port/DB_PORT');
});

it('fails fast when no github asset is configured for the selected platform', function (): void {
    $originalPath = getenv('PATH') ?: '';
    putenv('PATH=/tmp/lpg-tests-empty-path');

    config()->set('lpg.platform', 'windows-arm64v8');
    config()->set('lpg.embedded.asset', '');
    configureTestStorage();

    try {
        $message = runLpgExpectingFailure(['--port' => '55432']);
        expect($message)->toContain("No GitHub asset mapping for platform 'windows-arm64v8'");
    } finally {
        putenv("PATH={$originalPath}");
    }
});

it('fails with a clear preflight error when curl is unavailable for embedded downloads', function (): void {
    $originalPath = getenv('PATH') ?: '';
    putenv('PATH=/tmp/lpg-tests-empty-path');

    config()->set('lpg.platform', 'darwin-arm64v8');
    config()->set('lpg.embedded.asset', '');
    configureTestStorage();

    try {
        $message = runLpgExpectingFailure(['--port' => '55433']);
        expect($message)->toContain('Required system command not found: curl');
    } finally {
        putenv("PATH={$originalPath}");
    }
});

it('rejects non-tar-gz embedded assets before download', function (): void {
    $originalPath = getenv('PATH') ?: '';
    putenv('PATH=/tmp/lpg-tests-empty-path');

    config()->set('lpg.platform', 'darwin-arm64v8');
    config()->set('lpg.embedded.asset', 'postgres-darwin-arm_64.zip');
    configureTestStorage();

    try {
        $message = runLpgExpectingFailure(['--port' => '55434']);
        expect($message)->toContain('Unsupported GitHub asset type. Expected a .tar.gz asset');
    } finally {
        putenv("PATH={$originalPath}");
    }
});

it('does not use pg_ctl and initdb from PATH when resolving binaries', function (): void {
    $originalPath = getenv('PATH') ?: '';
    $fakeBinDir = sys_get_temp_dir().'/lpg-fake-bin-'.uniqid('', true);
    @mkdir($fakeBinDir, 0777, true);

    file_put_contents($fakeBinDir.'/pg_ctl', "#!/bin/sh\nexit 0\n");
    file_put_contents($fakeBinDir.'/initdb', "#!/bin/sh\nexit 0\n");
    @chmod($fakeBinDir.'/pg_ctl', 0755);
    @chmod($fakeBinDir.'/initdb', 0755);

    putenv("PATH={$fakeBinDir}");

    config()->set('lpg.platform', 'windows-arm64v8');
    config()->set('lpg.embedded.asset', '');
    configureTestStorage();

    try {
        $message = runLpgExpectingFailure(['--port' => '55435']);
        expect($message)->toContain("No GitHub asset mapping for platform 'windows-arm64v8'");
    } finally {
        putenv("PATH={$originalPath}");
    }
});
