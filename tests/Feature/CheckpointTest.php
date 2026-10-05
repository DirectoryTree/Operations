<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

use function Pest\Laravel\artisan;

test('standard commands work without the checkpoint table', function (bool $separateConnection) {
    if ($separateConnection) {
        config([
            'database.connections.operations' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
            ],
            'operations.connection' => 'operations',
        ]);

        File::getRequire(__DIR__.'/../../database/migrations/2026_10_02_165413_create_operations_table.php')->up();
    } else {
        Schema::drop('operation_checkpoints');
    }

    $connection = DB::connection(config('operations.connection'));
    $name = '2026_10_05_120000_example';

    File::ensureDirectoryExists(config('operations.path'));
    File::copy(
        __DIR__.'/../Fixtures/operations/successful_operation.php',
        config('operations.path')."/{$name}.php",
    );

    artisan('operations:run')->expectsOutputToContain('Operation executed.')->assertSuccessful();
    artisan('operations:run')->expectsOutputToContain('No pending operations.')->assertSuccessful();

    artisan('operations:status')->expectsTable(
        ['Operation', 'Status', 'Completed at', 'File'],
        [[$name, 'Completed', $connection->table('operations')->value('completed_at'), 'Present']],
    )->assertSuccessful();

    File::delete(config('operations.path')."/{$name}.php");

    artisan('operations:forget', ['name' => $name, '--force' => true])->assertSuccessful();
    artisan('operations:forget', ['name' => $name, '--force' => true])->assertFailed();

    expect($connection->table('operations')->count())->toBe(0)
        ->and($connection->getSchemaBuilder()->hasTable('operation_checkpoints'))->toBeFalse();

    config(['operations.connection' => null]);
})->with(['default connection' => false, 'separate connection' => true]);

test('a failed operation resumes from its saved checkpoint on the next run', function () {
    Schema::create('examples', function (Blueprint $table) {
        $table->id();
    });

    app()->instance('should_fail', true);

    File::ensureDirectoryExists(config('operations.path'));
    File::copy(
        __DIR__.'/../Fixtures/operations/resumable_backfill.php',
        config('operations.path').'/2026_10_05_120000_backfill.php',
    );

    expect(fn () => artisan('operations:run')->run())->toThrow(RuntimeException::class, 'Backfill interrupted.');

    expect(DB::table('examples')->pluck('id')->all())->toBe([1, 2])
        ->and(json_decode(DB::table('operation_checkpoints')->value('value')))->toBe(2)
        ->and(DB::table('operations')->count())->toBe(0);

    app()->instance('should_fail', false);

    artisan('operations:run')->assertSuccessful();
    artisan('operations:run')->expectsOutputToContain('No pending operations.')->assertSuccessful();

    expect(DB::table('examples')->pluck('id')->all())->toBe([1, 2, 3])
        ->and(json_decode(DB::table('operation_checkpoints')->value('value')))->toBe(3)
        ->and(DB::table('operations')->pluck('name')->all())->toBe(['2026_10_05_120000_backfill'])
        ->and(DB::table('operation_checkpoints')->value('operation'))->toBe('2026_10_05_120000_backfill');
});
