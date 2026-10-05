<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

use function Pest\Laravel\artisan;

test('the migration and runner use the configured ledger connection', function () {
    config([
        'database.connections.operations' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ],
        'operations.connection' => 'operations',
    ]);

    $migration = File::getRequire(__DIR__.'/../../database/migrations/2026_10_02_165413_create_operations_table.php');
    $migration->up();

    File::ensureDirectoryExists(config('operations.path'));
    File::copy(
        __DIR__.'/../Fixtures/operations/empty_operation.php',
        config('operations.path').'/2026_10_02_120000_example.php',
    );

    artisan('operations:run')->assertSuccessful();
    artisan('operations:run')->expectsOutputToContain('No pending operations.')->assertSuccessful();

    expect(DB::connection('operations')->table('operations')->count())->toBe(1)
        ->and(DB::table('operations')->count())->toBe(0);

    $migration->down();

    expect(Schema::connection('operations')->hasTable('operations'))->toBeFalse()
        ->and(Schema::hasTable('operations'))->toBeTrue();

    config(['operations.connection' => null]);
});

test('successful transactional operations commit their changes and completion together', function () {
    Schema::create('examples', function (Blueprint $table) {
        $table->id();
    });

    File::ensureDirectoryExists(config('operations.path'));
    File::copy(
        __DIR__.'/../Fixtures/operations/successful_transaction.php',
        config('operations.path').'/2026_10_02_120000_backfill.php',
    );

    artisan('operations:run')->assertSuccessful();

    expect(DB::table('examples')->count())->toBe(1)
        ->and(DB::table('operations')->count())->toBe(1)
        ->and(DB::transactionLevel())->toBe(0);
});

test('completion history is read from the writer when the connection has a read replica', function () {
    File::ensureDirectoryExists(config('operations.path'));
    $writer = config('operations.path').'/writer.sqlite';
    $reader = config('operations.path').'/reader.sqlite';
    File::put($writer, '');
    File::put($reader, '');

    config([
        'database.connections.operations' => [
            'driver' => 'sqlite',
            'database' => $writer,
            'read' => ['database' => $reader],
            'write' => ['database' => $writer],
            'prefix' => '',
        ],
        'operations.connection' => 'operations',
    ]);

    $migration = File::getRequire(__DIR__.'/../../database/migrations/2026_10_02_165413_create_operations_table.php');
    $migration->up();

    File::copy(
        __DIR__.'/../Fixtures/operations/record_execution.php',
        config('operations.path').'/2026_10_02_120000_example.php',
    );

    app()->instance('executed', collect());

    artisan('operations:run')->assertSuccessful();
    artisan('operations:run')->expectsOutputToContain('No pending operations.')->assertSuccessful();

    expect(app('executed')->all())->toBe(['example'])
        ->and(DB::connection('operations')->table('operations')->useWritePdo()->count())->toBe(1);

    config(['operations.connection' => null]);
});
