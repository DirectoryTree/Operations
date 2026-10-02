<?php

namespace DirectoryTree\Operations;

use DirectoryTree\Operations\Commands\MakeCommand;
use DirectoryTree\Operations\Commands\RunCommand;
use DirectoryTree\Operations\Commands\StatusCommand;
use Illuminate\Support\ServiceProvider;

class OperationsServiceProvider extends ServiceProvider
{
    /**
     * Register the package services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/operations.php', 'operations');

        $this->app->bind(OperationRepository::class, fn ($app) => new OperationRepository(
            $app['db']->connection($app['config']['operations.connection'])
        ));
    }

    /**
     * Bootstrap the package services.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([MakeCommand::class, RunCommand::class, StatusCommand::class]);

            $this->publishes([
                __DIR__.'/../config/operations.php' => config_path('operations.php'),
            ], 'operations-config');

            $this->publishesMigrations([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'operations-migrations');
        }
    }
}
