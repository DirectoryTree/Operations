<?php

use DirectoryTree\Operations\Commands\RunCommand;
use DirectoryTree\Operations\Runner;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

use function Pest\Laravel\artisan;

test('operations run in filename order and only once after completion', function () {
    File::ensureDirectoryExists(config('operations.path'));

    foreach (['2026_10_02_120002_second', '2026_10_02_120001_first'] as $name) {
        File::copy(
            __DIR__.'/../Fixtures/operations/record_execution_order.php',
            config('operations.path')."/{$name}.php",
        );
    }

    app()->instance('executed', collect());
    app()->instance('ledger_counts', collect());

    artisan('operations:run')->assertSuccessful();
    artisan('operations:run')->expectsOutputToContain('No pending operations.')->assertSuccessful();

    expect(app('executed')->all())->toBe(['2026_10_02_120001_first', '2026_10_02_120002_second'])
        ->and(app('ledger_counts')->all())->toBe([0, 1])
        ->and(DB::table('operations')->whereNotNull('completed_at')->count())->toBe(2);
});

test('a failed operation stops the run and the next run resumes without repeating completed work', function () {
    File::ensureDirectoryExists(config('operations.path'));

    foreach (['2026_10_02_120001_first', '2026_10_02_120002_second', '2026_10_02_120003_third'] as $name) {
        File::copy(
            __DIR__.'/../Fixtures/operations/resumable_operations.php',
            config('operations.path')."/{$name}.php",
        );
    }

    app()->instance('attempts', collect());
    app()->instance('should_fail', true);

    expect(fn () => Artisan::call('operations:run', ['--isolated' => true]))->toThrow(RuntimeException::class, 'Backfill failed.');

    expect(DB::table('operations')->pluck('name')->all())->toBe(['2026_10_02_120001_first']);

    app()->instance('should_fail', false);

    artisan('operations:run', ['--isolated' => true])->assertSuccessful();

    expect(app('attempts')->all())->toBe([
        '2026_10_02_120001_first',
        '2026_10_02_120002_second',
        '2026_10_02_120002_second',
        '2026_10_02_120003_third',
    ])->and(DB::table('operations')->count())->toBe(3);
});

test('completed operation files are not loaded on subsequent runs', function (?string $operation) {
    File::ensureDirectoryExists(config('operations.path'));
    File::copy(
        __DIR__.'/../Fixtures/operations/throw_on_load.php',
        config('operations.path').'/2026_10_02_120000_old.php',
    );

    DB::table('operations')->insert(['name' => '2026_10_02_120000_old', 'completed_at' => now()]);

    artisan('operations:run', ['operation' => $operation])->expectsOutputToContain('No pending operations.')->assertSuccessful();
})->with([null, '2026_10_02_120000_old']);

test('invalid operation files fail without being recorded', function () {
    File::ensureDirectoryExists(config('operations.path'));
    $path = config('operations.path').'/2026_10_02_120000_invalid.php';
    File::copy(
        __DIR__.'/../Fixtures/operations/invalid_operation.php',
        $path,
    );

    expect(fn () => app(Runner::class)->run(app(RunCommand::class)))->toThrow(UnexpectedValueException::class);

    expect(DB::table('operations')->count())->toBe(0);

    File::delete($path);

    artisan('operations:run')->assertSuccessful();
});

test('production requires confirmation unless forced', function () {
    app()->instance('env', 'production');

    artisan('operations:run')
        ->expectsConfirmation('Are you sure you want to run this command?', 'no')
        ->assertFailed();

    artisan('operations:run', ['--force' => true])->assertSuccessful();

    app()->instance('env', 'testing');
});

test('a missing operations directory has no pending work', function () {
    artisan('operations:run')->expectsOutputToContain('No pending operations.')->assertSuccessful();
});
