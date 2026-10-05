<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Symfony\Component\Console\Output\BufferedOutput;

test('operations can print messages tables and progress between their start and completion lines', function () {
    File::ensureDirectoryExists(config('operations.path'));
    File::copy(
        __DIR__.'/../Fixtures/operations/console_output.php',
        config('operations.path').'/2026_10_02_120000_output.php',
    );

    $output = new BufferedOutput;
    $exitCode = Artisan::call('operations:run', ['--force' => true], $output);
    $rendered = $output->fetch();

    expect($exitCode)->toBe(0)
        ->and($rendered)->toContain('RUNNING', 'Backfilling company names...', 'Updated', '2/2', '100%', 'Company names updated.', 'DONE')
        ->and($rendered)->toMatch('/\d+\.\d{2}s\s+DONE/')
        ->and(strpos($rendered, 'RUNNING'))->toBeLessThan(strpos($rendered, 'Backfilling company names...'))
        ->and(strpos($rendered, 'Company names updated.'))->toBeLessThan(strpos($rendered, 'DONE'))
        ->and(app('forced'))->toBeTrue()
        ->and(app('completed_during_operation'))->toBe(0)
        ->and(DB::table('operations')->count())->toBe(1);
});

test('quiet mode suppresses both operation output and runner status lines', function () {
    File::ensureDirectoryExists(config('operations.path'));
    File::copy(
        __DIR__.'/../Fixtures/operations/quiet_output.php',
        config('operations.path').'/2026_10_02_120000_quiet.php',
    );

    $output = new BufferedOutput;
    $exitCode = Artisan::call('operations:run', ['--quiet' => true], $output);

    expect($exitCode)->toBe(0)
        ->and($output->fetch())->toBe('')
        ->and(DB::table('operations')->count())->toBe(1);
});

test('failed operations never print a successful completion line', function (string $failure, string $exception) {
    File::ensureDirectoryExists(config('operations.path'));
    File::copy(
        __DIR__.'/../Fixtures/operations/failing_output.php',
        config('operations.path').'/2026_10_02_120000_failure.php',
    );

    app()->instance('failure', $failure);
    $output = new BufferedOutput;

    expect(fn () => Artisan::call('operations:run', [], $output))->toThrow($exception);

    expect($output->fetch())->toContain('RUNNING', 'Starting the backfill...')->not->toContain('DONE', 'Completed 1 operation(s).')
        ->and(DB::table('operations')->count())->toBe(0);
})->with([
    'operation failure' => ['operation', RuntimeException::class],
    'completion failure' => ['completion', QueryException::class],
]);
