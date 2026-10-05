<?php

use DirectoryTree\Operations\Commands\RunCommand;
use Illuminate\Console\CacheCommandMutex;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

use function Pest\Laravel\artisan;

test('isolated invocations skip pending operations when another command holds the lock', function (bool|string $isolated, int $exitCode) {
    File::ensureDirectoryExists(config('operations.path'));
    File::copy(
        __DIR__.'/../Fixtures/operations/throw_on_load.php',
        config('operations.path').'/2026_10_02_120000_backfill.php',
    );

    $mutex = app(CacheCommandMutex::class);
    $command = app(RunCommand::class);
    expect($mutex->create($command))->toBeTrue();

    try {
        artisan('operations:run', ['--isolated' => $isolated])
            ->expectsOutputToContain('The [operations:run] command is already running.')
            ->assertExitCode($exitCode);

        expect(DB::table('operations')->count())->toBe(0)
            ->and($mutex->create($command))->toBeFalse();
    } finally {
        $mutex->forget($command);
    }
})->with([
    'skip successfully' => [true, 0],
    'fail the deployment' => ['1', 1],
]);

test('command isolation is opt in', function () {
    File::ensureDirectoryExists(config('operations.path'));
    File::copy(
        __DIR__.'/../Fixtures/operations/empty_operation.php',
        config('operations.path').'/2026_10_02_120000_backfill.php',
    );

    $mutex = app(CacheCommandMutex::class);
    $command = app(RunCommand::class);
    expect($mutex->create($command))->toBeTrue();

    try {
        artisan('operations:run')
            ->expectsOutputToContain('Completed 1 operation(s).')
            ->assertSuccessful();

        expect(DB::table('operations')->count())->toBe(1)
            ->and($mutex->create($command))->toBeFalse();
    } finally {
        $mutex->forget($command);
    }
});

test('successful isolated runs release the lock for subsequent invocations', function () {
    File::ensureDirectoryExists(config('operations.path'));
    File::copy(
        __DIR__.'/../Fixtures/operations/empty_operation.php',
        config('operations.path').'/2026_10_02_120000_backfill.php',
    );

    artisan('operations:run', ['--isolated' => true])
        ->expectsOutputToContain('Completed 1 operation(s).')
        ->assertSuccessful();

    artisan('operations:run', ['--isolated' => true])
        ->expectsOutputToContain('No pending operations.')
        ->assertSuccessful();

    expect(DB::table('operations')->count())->toBe(1);
});
