<?php

namespace DirectoryTree\Operations;

use DirectoryTree\Operations\Commands\ForgetCommand;
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
            $this->commands([
                RunCommand::class,
                MakeCommand::class,
                StatusCommand::class,
                ForgetCommand::class,
            ]);

            $this->publishes([
                __DIR__.'/../config/operations.php' => config_path('operations.php'),
            ], 'operations-config');

            $this->publishesMigrations([
                __DIR__.'/../database/migrations/2026_10_02_165413_create_operations_table.php' => database_path('migrations/2026_10_02_165413_create_operations_table.php'),
            ], 'operations-migrations');

            $this->publishesMigrations([
                __DIR__.'/../database/migrations/2026_10_05_041651_create_operation_checkpoints_table.php' => database_path('migrations/2026_10_05_041651_create_operation_checkpoints_table.php'),
            ], ['operations-migrations', 'operations-checkpoints-migration']);
        }
    }
}
