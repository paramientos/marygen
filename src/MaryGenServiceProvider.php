<?php

namespace SoysalTan\MaryGen;

use Illuminate\Support\ServiceProvider;
use SoysalTan\MaryGen\Commands\MaryGenCommand;

class MaryGenServiceProvider extends ServiceProvider
{
    public function boot()
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                MaryGenCommand::class,
            ]);
        }

        $this->publishes([
            __DIR__.'/../config/marygen.php' => config_path('marygen.php'),
        ], 'config');
    }

    public function register()
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/marygen.php', 'marygen'
        );
    }
}
