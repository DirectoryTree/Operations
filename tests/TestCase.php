<?php

namespace DirectoryTree\Operations\Tests;

use DirectoryTree\Operations\OperationsServiceProvider;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [OperationsServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $app['config']->set('cache.default', 'array');
        $app['config']->set('operations.path', sys_get_temp_dir().'/operations-tests-'.Str::uuid());
        $app->useDatabasePath($app['config']['operations.path'].'/database');
        $app->useConfigPath($app['config']['operations.path'].'/config');
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(config('operations.path'));

        parent::tearDown();
    }
}
