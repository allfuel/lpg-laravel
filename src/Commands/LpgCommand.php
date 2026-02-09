<?php

namespace Fuel\Lpg\Commands;

use Illuminate\Console\Command;
use RuntimeException;
use Symfony\Component\Process\Process;

class LpgCommand extends Command
{
    protected $signature = 'lpg
        {--host= : Host to listen on}
        {--port= : Port to listen on (defaults to config lpg.port / DB_PORT / 5432)}
        {--data-dir= : Data directory (default: config lpg.data_dir)}
        {--log= : Log file (default: config lpg.log)}
        {--pg-version= : Embedded bundle version to download if needed}
        {--platform= : Embedded platform override (e.g. darwin-arm64v8)}
        {--embedded-repo= : GitHub repo (owner/name) for embedded releases}
        {--embedded-tag-prefix= : Git tag prefix (usually "v")}
        {--embedded-asset= : Override GitHub asset filename (defaults based on platform)}
        {--stop-timeout= : Seconds to wait for stop before giving up}';

    protected $description = 'Start a local Postgres server for development and stop it on exit.';

    public function handle(): int
    {
        $host = (string) ($this->option('host') ?: config('lpg.host', '127.0.0.1'));
        $port = $this->resolvePort((string) ($this->option('port') ?? ''), (string) config('lpg.port', 5432));

        $dataDir = (string) ($this->option('data-dir') ?: config('lpg.data_dir', storage_path('pgdata')));
        $logPath = (string) ($this->option('log') ?: config('lpg.log', storage_path('logs/postgres.log')));
        $stopTimeout = (int) ($this->option('stop-timeout') ?: config('lpg.stop_timeout', 10));

        $database = (string) config('lpg.database', 'postgres');
        $username = (string) config('lpg.username', 'postgres');
        $password = (string) config('lpg.password', '');

        $this->ensureDir(dirname($logPath));
        $this->ensureDir($dataDir);


        $binDir = $this->resolveBinDir();

        $pgCtl = $this->requireExecutable($binDir, 'pg_ctl');
        $initdb = $this->requireExecutable($binDir, 'initdb');
        $pgIsReady = $this->executableOrNull($binDir, 'pg_isready');

        $weStarted = false;
        $started = false;
        $shutdownRan = false;

        $shutdown = function () use (&$shutdownRan, &$started, &$weStarted, $pgCtl, $dataDir, $stopTimeout): void {
            if ($shutdownRan) {
                return;
            }

            $shutdownRan = true;

            if (! $started || ! $weStarted) {
                return;
            }

            $this->line('');
            $this->line('Stopping Postgres...');

            try {
                $this->runOrThrow([$pgCtl, '-D', $dataDir, '-m', 'fast', '-t', (string) $stopTimeout, 'stop'], null);
                $weStarted = false;
                $started = false;
            } catch (\Throwable $e) {
                $msg = $e->getMessage();
                if (str_contains($msg, 'postmaster.pid') && str_contains($msg, 'does not exist')) {
                    return;
                }

                $this->warn('Postgres stop failed: '.$msg);
            }
        };

        register_shutdown_function($shutdown);
        $this->installSignalHandlers($shutdown);

        if (! $this->isInitialized($dataDir)) {
            $this->line('Initializing Postgres data directory...');
            $this->runOrThrow([
                $initdb,
                '-D', $dataDir,
                '--username='.$username,
                '--auth=trust',
                '--no-instructions',
            ], null);
        }

        if ($this->isRunning($pgCtl, $dataDir)) {
            $runningPort = $this->readPortFromPid($dataDir);
            if ($runningPort !== null && $runningPort !== $port) {
                $this->error("Postgres is already running for {$dataDir} on port {$runningPort}, but --port/config is {$port}.");
                $this->line('Stop the existing instance or change DB_PORT to match.');
                return self::FAILURE;
            }

            if ($pgIsReady) {
                $p = new Process([$pgIsReady, '-h', $host, '-p', (string) $port]);
                $p->setTimeout(2);
                $p->run();
                if (! $p->isSuccessful()) {
                    $this->error("Postgres is running for {$dataDir} but is not accepting connections on {$host}:{$port}.");
                    $this->line('Stop the existing instance or change DB_PORT to match.');
                    return self::FAILURE;
                }
            }

            $this->info("Postgres already running on {$host}:{$port} for this data directory; leaving it running on exit.");
            $started = true;
            $weStarted = false;
        } else {
            if (! $this->isTcpPortAvailable($host, $port)) {
                $this->error("Port {$port} is already in use. Set DB_PORT to a free port and try again.");
                return self::FAILURE;
            }

            $this->line("Starting Postgres on {$host}:{$port}...");

            $options = sprintf('-p %d -h %s', $port, $host);

            $this->runOrThrow([
                $pgCtl,
                '-D', $dataDir,
                '-l', $logPath,
                '-o', $options,
                'start',
            ], null);

            $weStarted = true;
            $started = true;

            $this->waitForReady($pgIsReady, $host, $port);
            $this->info('Postgres is ready.');
        }

        if ($database !== 'postgres') {
            $this->warn("DB_DATABASE is set to '{$database}'. This command does not create databases; use postgres or create it yourself.");
        }

        $this->printConnectionInfo($host, $port, $database, $username, $password);
        $this->line('Press Ctrl+C to stop (or let composer scripts manage the process).');

        while (true) {
            sleep(1);
        }
    }

    private function resolvePort(string $raw, string $fallback): int
    {
        $raw = trim($raw);
        if ($raw === '') {
            $raw = trim($fallback);
        }

        if (! ctype_digit($raw)) {
            throw new RuntimeException('Invalid --port/DB_PORT; expected 1..65535.');
        }

        $port = (int) $raw;
        if ($port <= 0 || $port > 65535) {
            throw new RuntimeException('Invalid --port/DB_PORT; expected 1..65535.');
        }

        return $port;
    }

    private function isTcpPortAvailable(string $host, int $port): bool
    {
        $server = @stream_socket_server(
            "tcp://{$host}:{$port}",
            $errno,
            $errstr,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN
        );

        if ($server === false) {
            return false;
        }

        fclose($server);
        return true;
    }

    private function readPortFromPid(string $dataDir): ?int
    {
        $pidPath = rtrim($dataDir, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'postmaster.pid';
        if (! is_file($pidPath)) {
            return null;
        }

        $lines = @file($pidPath, FILE_IGNORE_NEW_LINES);
        if (! is_array($lines) || count($lines) < 4) {
            return null;
        }

        $port = trim((string) $lines[3]);
        if (! ctype_digit($port)) {
            return null;
        }

        $value = (int) $port;
        return ($value > 0 && $value <= 65535) ? $value : null;
    }

    private function printConnectionInfo(string $host, int $port, string $database, string $username, string $password): void
    {
        $this->line('');
        $this->info('Connection info:');
        $this->line("  Host:     {$host}");
        $this->line("  Port:     {$port}");
        $this->line("  Database: {$database}");
        $this->line("  Username: {$username}");
        $this->line('  Password: '.($password === '' ? '(empty)' : '(set)'));
        $this->line('');
    }

    private function resolveBinDir(): string
    {
        $systemPgCtl = $this->which('pg_ctl');
        $systemInitdb = $this->which('initdb');

        if ($systemPgCtl && $systemInitdb) {
            return dirname($systemPgCtl);
        }


        $platform = (string) ($this->option('platform') ?: config('lpg.platform', ''));
        if ($platform === '') {
            $platform = $this->defaultEmbeddedPlatform();
        }

        $version = (string) ($this->option('pg-version') ?: config('lpg.version', '18.1-pgvector0.8.1'));

        return $this->ensureEmbeddedBinaries($platform, $version);
    }

    private function ensureEmbeddedBinaries(string $platform, string $version): string
    {
        $baseDir = $this->storageBaseDir();
        $installDir = $baseDir.DIRECTORY_SEPARATOR.'embedded'.DIRECTORY_SEPARATOR.$platform.DIRECTORY_SEPARATOR.$version;
        $this->ensureDir($installDir);

        $cachedBinDir = $installDir.DIRECTORY_SEPARATOR.'.bin-dir';
        if (is_file($cachedBinDir)) {
            $binDir = trim((string) @file_get_contents($cachedBinDir));
            if ($binDir !== '' && $this->executableOrNull($binDir, 'pg_ctl')) {
                return $binDir;
            }
        }

        $existing = $this->findFirst($installDir, 'pg_ctl');
        if (! $existing && PHP_OS_FAMILY === 'Windows') {
            $existing = $this->findFirst($installDir, 'pg_ctl.exe');
        }

        if ($existing) {
            $binDir = dirname($existing);
            @file_put_contents($cachedBinDir, $binDir);
            return $binDir;
        }

        $this->downloadAndExtractFromGitHub($platform, $version, $installDir);

        $pgCtl = $this->findFirst($installDir, 'pg_ctl');
        if (! $pgCtl && PHP_OS_FAMILY === 'Windows') {
            $pgCtl = $this->findFirst($installDir, 'pg_ctl.exe');
        }

        if (! $pgCtl) {
            throw new RuntimeException('Failed to locate pg_ctl after extracting embedded Postgres binaries.');
        }

        $binDir = dirname($pgCtl);
        @file_put_contents($cachedBinDir, $binDir);

        return $binDir;
    }

    private function downloadAndExtractFromGitHub(string $platform, string $version, string $installDir): void
    {
        $repo = (string) ($this->option('embedded-repo') ?: config('lpg.embedded.repo', 'allfuel/lpg'));

        $tagPrefixOption = $this->option('embedded-tag-prefix');
        $tagPrefix = $tagPrefixOption !== null
            ? (string) $tagPrefixOption
            : (string) config('lpg.embedded.tag_prefix', 'v');

        $assetOption = $this->option('embedded-asset');
        $assetOverride = $assetOption !== null
            ? (string) $assetOption
            : (string) config('lpg.embedded.asset', '');

        if (! str_contains($repo, '/')) {
            throw new RuntimeException('Invalid --embedded-repo. Expected "owner/name".');
        }

        $tag = $this->buildGitTag($version, $tagPrefix);
        $asset = $assetOverride !== '' ? $assetOverride : $this->defaultGitHubAssetForPlatform($platform);
        if (! str_ends_with($asset, '.tar.gz')) {
            throw new RuntimeException('Unsupported GitHub asset type. Expected a .tar.gz asset; set --embedded-asset accordingly.');
        }

        $baseDir = $this->storageBaseDir();
        $downloadsDir = $baseDir.DIRECTORY_SEPARATOR.'downloads'.DIRECTORY_SEPARATOR.'github';
        $this->ensureDir($downloadsDir);
        $curl = $this->requireSystemCommand('curl', 'Required to download embedded Postgres assets from GitHub.');
        $tar = $this->requireSystemCommand('tar', 'Required to extract .tar.gz embedded Postgres assets.');

        $safeRepo = str_replace(['/', '\\'], '__', $repo);
        $assetPath = $downloadsDir.DIRECTORY_SEPARATOR.$safeRepo.'__'.$tag.'__'.$asset;
        $url = "https://github.com/{$repo}/releases/download/{$tag}/{$asset}";

        if (! is_file($assetPath)) {
            $this->line("Downloading embedded Postgres bundle from GitHub ({$repo} {$tag})...");
            $this->runOrThrow([$curl, '-fL', '-o', $assetPath, $url], null);
        }

        try {
            $this->runOrThrow([$tar, '-xzf', $assetPath, '-C', $installDir], null);
        } catch (\Throwable $e) {
            throw new RuntimeException(
                'Failed to extract embedded Postgres asset (.tar.gz). Ensure your tar build supports gzip extraction (-z). '.$e->getMessage()
            );
        }
    }

    private function buildGitTag(string $version, string $tagPrefix): string
    {
        $version = trim($version);
        $tagPrefix = (string) $tagPrefix;

        if ($tagPrefix === '') {
            return $version;
        }

        if (str_starts_with($version, $tagPrefix)) {
            return $version;
        }

        if ($tagPrefix === 'v' && str_starts_with($version, 'v')) {
            return $version;
        }

        return $tagPrefix.$version;
    }

    private function defaultGitHubAssetForPlatform(string $platform): string
    {
        $map = config('lpg.embedded.assets', []);
        if (is_array($map)) {
            $asset = $map[$platform] ?? null;
            if (is_string($asset) && $asset !== '') {
                return $asset;
            }
        }

        throw new RuntimeException("No GitHub asset mapping for platform '{$platform}'. Configure lpg.embedded.assets or provide --embedded-asset.");
    }

    private function defaultEmbeddedPlatform(): string
    {
        $os = PHP_OS_FAMILY;
        $arch = strtolower((string) php_uname('m'));

        if ($os === 'Darwin') {
            return str_contains($arch, 'arm') ? 'darwin-arm64v8' : 'darwin-amd64';
        }

        if ($os === 'Linux') {
            if (in_array($arch, ['aarch64', 'arm64'], true)) {
                return 'linux-arm64v8';
            }

            return 'linux-amd64';
        }

        if ($os === 'Windows') {
            if (in_array($arch, ['aarch64', 'arm64'], true)) {
                return 'windows-arm64v8';
            }

            return 'windows-amd64';
        }

        throw new RuntimeException("Unsupported OS for embedded Postgres download: {$os}. Install Postgres so pg_ctl and initdb are on PATH.");
    }

    private function isInitialized(string $dataDir): bool
    {
        return is_file($dataDir.DIRECTORY_SEPARATOR.'PG_VERSION');
    }

    private function isRunning(string $pgCtl, string $dataDir): bool
    {
        $process = new Process([$pgCtl, '-D', $dataDir, 'status']);
        $process->setTimeout(5);
        $process->run();

        return $process->isSuccessful();
    }

    private function waitForReady(?string $pgIsReady, string $host, int $port): void
    {
        if (! $pgIsReady) {
            return;
        }

        $deadline = microtime(true) + 15.0;

        while (microtime(true) < $deadline) {
            $p = new Process([$pgIsReady, '-h', $host, '-p', (string) $port]);
            $p->setTimeout(2);
            $p->run();
            if ($p->isSuccessful()) {
                return;
            }

            usleep(200000);
        }

        $this->warn('Timed out waiting for Postgres readiness; check the log file.');
    }

    private function installSignalHandlers(callable $shutdown): void
    {
        if (! function_exists('pcntl_signal') || ! function_exists('pcntl_async_signals')) {
            return;
        }

        pcntl_async_signals(true);

        pcntl_signal(SIGINT, function () use ($shutdown): void {
            $shutdown();
            exit(130);
        });

        pcntl_signal(SIGTERM, function () use ($shutdown): void {
            $shutdown();
            exit(143);
        });
    }

    private function which(string $binary): ?string
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $process = new Process(['where', $binary]);
        } else {
            $process = new Process(['sh', '-lc', 'command -v '.escapeshellarg($binary)]);
        }

        $process->setTimeout(2);
        $process->run();

        if (! $process->isSuccessful()) {
            return null;
        }

        $output = trim(str_replace("\r", "\n", $process->getOutput()));
        if ($output === '') {
            return null;
        }

        foreach (preg_split('/\n+/', $output) as $line) {
            $path = trim((string) $line);
            if ($path !== '' && is_file($path)) {
                return $path;
            }
        }

        return null;
    }

    private function requireExecutable(string $binDir, string $name): string
    {
        $path = $this->executableOrNull($binDir, $name);
        if (! $path) {
            throw new RuntimeException("Required executable not found: {$name} (bin dir: {$binDir})");
        }

        return $path;
    }

    private function requireSystemCommand(string $name, string $hint): string
    {
        $path = $this->which($name);
        if (! $path) {
            throw new RuntimeException("Required system command not found: {$name}. {$hint}");
        }

        return $path;
    }

    private function executableOrNull(string $binDir, string $name): ?string
    {
        $binDir = rtrim($binDir, DIRECTORY_SEPARATOR);
        $candidates = [$binDir.DIRECTORY_SEPARATOR.$name];

        if (PHP_OS_FAMILY === 'Windows') {
            $candidates[] = $binDir.DIRECTORY_SEPARATOR.$name.'.exe';
        }

        foreach ($candidates as $candidate) {
            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function ensureDir(string $dir): void
    {
        if (is_dir($dir)) {
            return;
        }

        if (! @mkdir($dir, 0777, true) && ! is_dir($dir)) {
            throw new RuntimeException("Failed to create directory: {$dir}");
        }
    }

    private function runOrThrow(array $command, ?string $cwd): void
    {
        $process = new Process($command, $cwd);
        $process->setTimeout(null);
        $process->run(function (string $type, string $buffer): void {
            $this->output->write($buffer);
        });

        if (! $process->isSuccessful()) {
            $error = trim($process->getErrorOutput());
            if ($error === '') {
                $error = trim($process->getOutput());
            }

            throw new RuntimeException($error !== '' ? $error : 'Command failed: '.implode(' ', $command));
        }
    }

    private function findFirst(string $dir, string $basename): ?string
    {
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($it as $file) {
            if (! $file->isFile()) {
                continue;
            }

            if ($file->getBasename() === $basename) {
                return $file->getPathname();
            }
        }

        return null;
    }

    private function storageBaseDir(): string
    {
        return (string) config('lpg.storage_dir', storage_path('pg'));
    }
}
