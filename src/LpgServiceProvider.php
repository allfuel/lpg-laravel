<?php

namespace Fuel\Lpg;

use Fuel\Lpg\Commands\LpgCommand;
use Illuminate\Support\ServiceProvider;

class LpgServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/lpg.php', 'lpg');
    }

    public function boot(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../config/lpg.php' => config_path('lpg.php'),
        ], 'lpg-config');

        $this->commands([
            LpgCommand::class,
        ]);
    }
}
