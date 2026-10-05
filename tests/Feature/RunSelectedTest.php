<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

use function Pest\Laravel\artisan;
use function Pest\Laravel\freezeTime;
use function Pest\Laravel\travel;

test('only the selected operation runs and is skipped after completion', function () {
    freezeTime();

    File::ensureDirectoryExists(config('operations.path'));

    foreach (['2026_10_03_110000_unrelated', '2026_10_03_130000_unrelated'] as $name) {
        File::copy(
            __DIR__.'/../Fixtures/operations/throw_on_load.php',
            config('operations.path')."/{$name}.php",
        );
    }

    File::copy(
        __DIR__.'/../Fixtures/operations/selected_operation.php',
        config('operations.path').'/2026_10_03_120000_selected.php',
    );

    artisan('operations:run', ['operation' => '2026_10_03_120000_selected'])
        ->expectsOutputToContain('Selected operation executed.')
        ->expectsOutputToContain('Completed 1 operation(s).')
        ->assertSuccessful();

    $completedAt = DB::table('operations')->value('completed_at');

    travel(1)->minute();

    artisan('operations:run', ['operation' => '2026_10_03_120000_selected'])
        ->expectsOutputToContain('No pending operations.')
        ->assertSuccessful();

    expect(DB::table('operations')->pluck('name')->all())->toBe(['2026_10_03_120000_selected'])
        ->and($completedAt)->not->toBeNull()
        ->and(DB::table('operations')->value('completed_at'))->toBe($completedAt);
});

test('invalid selections fail clearly without loading any operations', function (string $operation, string $message) {
    File::ensureDirectoryExists(config('operations.path'));

    foreach (['2026_10_03_120000_backfill', '2026_10_03_130000_backfill'] as $name) {
        File::copy(
            __DIR__.'/../Fixtures/operations/throw_on_load.php',
            config('operations.path')."/{$name}.php",
        );
    }

    expect(fn () => artisan('operations:run', [
        'operation' => $operation,
    ])->run())->toThrow(InvalidArgumentException::class, $message);

    expect(DB::table('operations')->count())->toBe(0);
})->with([
    'missing filename' => ['2026_10_03_140000_missing', 'not found'],
    'ambiguous suffix' => ['backfill', 'not found'],
    'ambiguous prefix' => ['2026_10_03', 'not found'],
    'empty argument' => ['', 'Use an exact operation filename'],
    'whitespace' => [' ', 'Use an exact operation filename'],
    'extension' => ['2026_10_03_120000_backfill.php', 'Use an exact operation filename'],
    'relative path' => ['./2026_10_03_120000_backfill', 'Use an exact operation filename'],
    'absolute path' => ['/2026_10_03_120000_backfill', 'Use an exact operation filename'],
    'traversal' => ['../2026_10_03_120000_backfill', 'Use an exact operation filename'],
    'windows traversal' => ['..\\2026_10_03_120000_backfill', 'Use an exact operation filename'],
    'wildcard' => ['2026_10_03_*', 'Use an exact operation filename'],
]);

test('a selected filename must exist even when already completed', function () {
    DB::table('operations')->insert([
        'name' => '2026_10_03_120000_missing',
        'completed_at' => now(),
    ]);

    expect(fn () => artisan('operations:run', ['operation' => '2026_10_03_120000_missing'])->run())
        ->toThrow(InvalidArgumentException::class, 'Operation [2026_10_03_120000_missing] not found.');
});
