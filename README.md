# lpg-laravel

`fueldotbuild/lpg` provides an `artisan lpg` command that starts a local Postgres server for Laravel development, downloading embedded binaries from release artifacts.

## Install

```bash
composer require fueldotbuild/lpg --dev
```

## Usage


**composer run dev**:
Add it to your `composer.json` for `dev`. It will then always be available using the port in your `.env`.

e.g.
```json
"dev": [
  "Composer\\Config::disableProcessTimeout",
  "npx concurrently -c \"#93c5fd,#34d399,#c4b5fd,#fb7185,#fdba74\" \"php artisan lpg\" \"php artisan serve\" \"php artisan queue:listen --tries=1 --timeout=0\" \"php artisan pail --timeout=0\" \"npm run dev\" --names=lpg,server,queue,logs,vite --kill-others"
],
```


**Or run it manually:**
```bash
php artisan lpg [--port=5455]
```

## Configuration

Publish config:

```bash
php artisan vendor:publish --tag=lpg-config
```

Then edit `config/lpg.php`.

## Embedded Requirements

When using embedded binaries from GitHub releases, `lpg` expects:

- `curl` to download assets
- `tar` with gzip support (`-z`) to extract `.tar.gz` assets

Only `.tar.gz` embedded assets are supported.
The active embedded runtime is materialized under `storage/pg`, with executables at `storage/pg/bin`.

## Testing

Install dev dependencies and run tests:

```bash
composer install
composer test
```
