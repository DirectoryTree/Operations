<?php

use DirectoryTree\Operations\Commands\RunCommand;
use Illuminate\Foundation\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

use function Pest\Laravel\artisan;

test('a selected operation runs through the runner and is skipped after completion', function () {
    File::ensureDirectoryExists(config('operations.path'));

    foreach (['2026_10_03_110000_unrelated', '2026_10_03_130000_unrelated'] as $name) {
        File::put(config('operations.path')."/{$name}.php", '<?php throw new \RuntimeException("Unrelated operation loaded.");');
    }

    File::put(config('operations.path').'/2026_10_03_120000_selected.php', <<<'PHP'
    <?php

    return new class extends \DirectoryTree\Operations\Operation implements \DirectoryTree\Operations\Contracts\WithinTransaction {
        public function handle(\Illuminate\Console\Command $command): void
        {
            app('transaction_levels')->push(\Illuminate\Support\Facades\DB::transactionLevel());
            $command->info('Selected operation executed.');
        }
    };
    PHP);

    app()->instance('transaction_levels', collect());

    artisan('operations:run', ['operation' => '2026_10_03_120000_selected'])
        ->expectsOutputToContain('Selected operation executed.')
        ->expectsOutputToContain('Completed 1 operation(s).')
        ->assertSuccessful();

    $completedAt = DB::table('operations')->value('completed_at');

    artisan('operations:run', ['operation' => '2026_10_03_120000_selected'])
        ->expectsOutputToContain('No pending operations.')
        ->assertSuccessful();

    expect(app('transaction_levels')->all())->toBe([1])
        ->and(DB::table('operations')->pluck('name')->all())->toBe(['2026_10_03_120000_selected'])
        ->and($completedAt)->not->toBeNull()
        ->and(DB::table('operations')->value('completed_at'))->toBe($completedAt);
});

test('invalid selections fail clearly without loading any operations', function (string $operation, string $message) {
    File::ensureDirectoryExists(config('operations.path'));

    foreach (['2026_10_03_120000_backfill', '2026_10_03_130000_backfill'] as $name) {
        File::put(config('operations.path')."/{$name}.php", '<?php throw new \RuntimeException("Unrelated operation loaded.");');
    }

    $output = new BufferedOutput;
    // Testbench rethrows console exceptions; use Laravel's kernel to verify the exit code.
    $kernel = new Kernel(app(), app('events'));
    $kernel->registerCommand(app(RunCommand::class));
    $exitCode = $kernel->handle(new ArrayInput([
        'command' => 'operations:run',
        'operation' => $operation,
    ]), $output);

    expect($exitCode)->toBe(1)
        ->and($output->fetch())->toContain($message)
        ->and(DB::table('operations')->count())->toBe(0);
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
