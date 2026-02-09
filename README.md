# lpg-laravel

`allfuel/lpg-laravel` provides an `artisan lpg` command that starts a local Postgres server for Laravel development, using system binaries or downloading embedded binaries from release artifacts.

## Install

```bash
composer require allfuel/lpg-laravel
```

## Usage

```bash
php artisan lpg
```

Optional flags:

```bash
php artisan lpg --use-system
php artisan lpg --port=5433
php artisan lpg --pg-version=18.1-pgvector0.8.1
php artisan lpg --embedded-source=github
php artisan lpg --embedded-repo=allfuel/lpg
```

## Configuration

Publish config:

```bash
php artisan vendor:publish --tag=lpg-config
```

Then edit `config/lpg.php`.
