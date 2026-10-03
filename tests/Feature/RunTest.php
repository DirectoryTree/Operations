<?php

use DirectoryTree\Operations\Commands\RunCommand;
use DirectoryTree\Operations\Runner;
use DirectoryTree\Operations\Tests\Fixtures\ExampleJob;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

use function Pest\Laravel\artisan;

test('operations run in filename order and only once after completion', function () {
    File::ensureDirectoryExists(config('operations.path'));

    foreach (['2026_10_02_120002_second', '2026_10_02_120001_first'] as $name) {
        File::put(config('operations.path')."/{$name}.php", <<<'PHP'
        <?php

        return new class extends \DirectoryTree\Operations\Operation {
            public function handle(\Illuminate\Console\Command $command): void
            {
                app('executed')->push(basename(__FILE__, '.php'));
                app('ledger_counts')->push(\Illuminate\Support\Facades\DB::table('operations')->count());
            }
        };
        PHP);
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
        File::put(config('operations.path')."/{$name}.php", <<<'PHP'
        <?php

        return new class extends \DirectoryTree\Operations\Operation {
            public function handle(\Illuminate\Console\Command $command): void
            {
                $name = basename(__FILE__, '.php');
                app('attempts')->push($name);

                if (str_ends_with($name, 'second') && app('should_fail')) {
                    throw new \RuntimeException('Backfill failed.');
                }
            }
        };
        PHP);
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

test('a transaction rolls back database changes when an operation fails', function (?string $operation) {
    Schema::create('examples', function (Blueprint $table) {
        $table->id();
    });

    File::ensureDirectoryExists(config('operations.path'));
    File::put(config('operations.path').'/2026_10_02_120000_backfill.php', <<<'PHP'
    <?php

    return new class extends \DirectoryTree\Operations\Operation implements \DirectoryTree\Operations\Contracts\WithinTransaction {
        public function handle(\Illuminate\Console\Command $command): void
        {
            \Illuminate\Support\Facades\DB::table('examples')->insert(['id' => 1]);

            throw new \RuntimeException('Backfill failed.');
        }
    };
    PHP);

    expect(fn () => Artisan::call('operations:run', ['operation' => $operation]))->toThrow(RuntimeException::class, 'Backfill failed.');

    expect(DB::table('examples')->count())->toBe(0)
        ->and(DB::table('operations')->count())->toBe(0);
})->with([null, '2026_10_02_120000_backfill']);

test('a transaction rolls back the operation if recording completion fails', function (?string $operation) {
    Schema::create('examples', function (Blueprint $table) {
        $table->id();
    });

    File::ensureDirectoryExists(config('operations.path'));
    File::put(config('operations.path').'/2026_10_02_120000_backfill.php', <<<'PHP'
    <?php

    return new class extends \DirectoryTree\Operations\Operation implements \DirectoryTree\Operations\Contracts\WithinTransaction {
        public function handle(\Illuminate\Console\Command $command): void
        {
            \Illuminate\Support\Facades\DB::table('examples')->insert(['id' => 1]);
            \Illuminate\Support\Facades\DB::table('operations')->insert([
                'name' => basename(__FILE__, '.php'),
                'completed_at' => now(),
            ]);
        }
    };
    PHP);

    expect(fn () => Artisan::call('operations:run', ['operation' => $operation]))->toThrow(QueryException::class);

    expect(DB::table('examples')->count())->toBe(0)
        ->and(DB::table('operations')->count())->toBe(0);
})->with([null, '2026_10_02_120000_backfill']);

test('operations can dispatch jobs without becoming queued operations themselves', function () {
    Bus::fake();

    File::ensureDirectoryExists(config('operations.path'));
    File::put(config('operations.path').'/2026_10_02_120000_dispatch.php', <<<'PHP'
    <?php

    return new class extends \DirectoryTree\Operations\Operation {
        public function handle(\Illuminate\Console\Command $command): void
        {
            \Illuminate\Support\Facades\Bus::dispatch(new \DirectoryTree\Operations\Tests\Fixtures\ExampleJob);
        }
    };
    PHP);

    artisan('operations:run')->assertSuccessful();

    Bus::assertDispatched(ExampleJob::class);
    Bus::assertDispatchedTimes(ExampleJob::class, 1);

    expect(DB::table('operations')->count())->toBe(1);
});

test('completed operation files are not loaded on subsequent runs', function (?string $operation) {
    File::ensureDirectoryExists(config('operations.path'));
    File::put(config('operations.path').'/2026_10_02_120000_old.php', '<?php throw new \RuntimeException("Obsolete dependency.");');

    DB::table('operations')->insert(['name' => '2026_10_02_120000_old', 'completed_at' => now()]);

    artisan('operations:run', ['operation' => $operation])->expectsOutputToContain('No pending operations.')->assertSuccessful();
})->with([null, '2026_10_02_120000_old']);

test('invalid operation files fail without being recorded', function () {
    File::ensureDirectoryExists(config('operations.path'));
    $path = config('operations.path').'/2026_10_02_120000_invalid.php';
    File::put($path, '<?php return new stdClass;');

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

test('operation exceptions produce a failing console exit code', function (?string $operation) {
    File::ensureDirectoryExists(config('operations.path'));
    File::put(config('operations.path').'/2026_10_02_120000_failure.php', <<<'PHP'
    <?php

    return new class extends \DirectoryTree\Operations\Operation {
        public function handle(\Illuminate\Console\Command $command): void
        {
            throw new \RuntimeException('Backfill failed.');
        }
    };
    PHP);

    $output = new BufferedOutput;
    // Testbench rethrows console exceptions; use Laravel's kernel to verify the exit code.
    $kernel = new Kernel(app(), app('events'));
    $kernel->registerCommand(app(RunCommand::class));
    $exitCode = $kernel->handle(new ArrayInput(['command' => 'operations:run', 'operation' => $operation]), $output);

    expect($exitCode)->toBe(1)
        ->and($output->fetch())->toContain('Backfill failed.')
        ->and(DB::table('operations')->count())->toBe(0);
})->with([null, '2026_10_02_120000_failure']);
