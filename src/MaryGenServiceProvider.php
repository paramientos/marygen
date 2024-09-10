<?php

namespace SoysalTan\MaryGen;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\ServiceProvider;
use SoysalTan\MaryGen\Commands\MaryGenCommand;
use SoysalTan\MaryGen\Facades\MaryGen;

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
            __DIR__ . '/../config/marygen.php' => config_path('marygen.php'),
        ], 'config');

        if (config('marygen.use_mg_like_eloquent_directive')) {
            Builder::macro('mgLike', function ($attributes, string $searchTerm) {
                $this->where(function (Builder $query) use ($attributes, $searchTerm) {
                    foreach (Arr::wrap($attributes) as $attribute) {
                        $query->when(
                            str_contains($attribute, '.'),
                            function (Builder $query) use ($attribute, $searchTerm) {
                                [$relationName, $relationAttribute] = explode('.', $attribute);

                                $query->orWhereHas($relationName, function (Builder $query) use ($relationAttribute, $searchTerm) {
                                    $query->where($relationAttribute, 'ILIKE', "%{$searchTerm}%");
                                });
                            },
                            function (Builder $query) use ($attribute, $searchTerm) {
                                $query->orWhere($attribute, 'ILIKE', "%{$searchTerm}%");
                            }
                        );
                    }
                });

                return $this;
            });
        }
    }

    public function provides()
    {
        return [
            \SoysalTan\MaryGen\MaryGen::class
        ];
    }

    public function register()
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/marygen.php', 'marygen'
        );

        // Register the service the package provides.
        $this->app->singleton('marygen', function ($app) {
            return new MaryGen();
        });
    }
}
