<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Symfony\Component\Console\Output\BufferedOutput;

use function Pest\Laravel\artisan;

test('forgetting deletes only the requested completion record without loading or deleting its file', function () {
    $name = '2026_10_02_120000_example';
    $other = '2026_10_02_120001_other';

    DB::table('operations')->insert([
        ['name' => $name, 'completed_at' => now()],
        ['name' => $other, 'completed_at' => now()],
    ]);

    File::ensureDirectoryExists(config('operations.path'));
    $path = config('operations.path')."/{$name}.php";
    File::copy(
        __DIR__.'/../Fixtures/operations/throw_on_load.php',
        $path,
    );

    artisan('operations:forget', ['name' => $name])
        ->expectsOutputToContain('Forgetting does not undo previous effects.')
        ->expectsConfirmation("Forget operation [{$name}]?", 'yes')
        ->expectsOutputToContain("Operation [{$name}] forgotten.")
        ->assertSuccessful();

    expect(DB::table('operations')->pluck('name')->all())->toBe([$other])
        ->and(File::get($path))->toBe(File::get(__DIR__.'/../Fixtures/operations/throw_on_load.php'));
});

test('a forgotten operation runs again only when the runner is invoked', function () {
    $name = '2026_10_02_120000_example';

    File::ensureDirectoryExists(config('operations.path'));
    File::copy(
        __DIR__.'/../Fixtures/operations/record_execution.php',
        config('operations.path')."/{$name}.php",
    );

    app()->instance('executed', collect());

    artisan('operations:run')->assertSuccessful();
    artisan('operations:forget', ['name' => $name, '--force' => true])->assertSuccessful();

    expect(app('executed')->all())->toBe(['example'])
        ->and(DB::table('operations')->count())->toBe(0);

    artisan('operations:status')->expectsTable(
        ['Operation', 'Status', 'Completed at', 'File'],
        [[$name, 'Pending', '—', 'Present']],
    )->assertSuccessful();

    artisan('operations:run')->assertSuccessful();
    artisan('operations:run')->expectsOutputToContain('No pending operations.')->assertSuccessful();

    expect(app('executed')->all())->toBe(['example', 'example'])
        ->and(DB::table('operations')->count())->toBe(1);
});

test('declining confirmation leaves the completion record intact in every environment', function (string $environment) {
    app()->instance('env', $environment);
    $name = '2026_10_02_120000_example';
    DB::table('operations')->insert(['name' => $name, 'completed_at' => now()]);
    DB::table('operation_checkpoints')->insert([
        'operation' => $name,
        'key' => 'last_id',
        'value' => '10',
    ]);

    artisan('operations:forget', ['name' => $name])
        ->expectsConfirmation("Forget operation [{$name}]?", 'no')
        ->expectsOutputToContain('Command cancelled.')
        ->assertFailed();

    expect(DB::table('operations')->pluck('name')->all())->toBe([$name])
        ->and(DB::table('operation_checkpoints')->pluck('operation')->all())->toBe([$name]);

    app()->instance('env', 'testing');
})->with(['testing', 'production']);

test('noninteractive invocations require force to forget an operation', function () {
    $name = '2026_10_02_120000_example';
    DB::table('operations')->insert(['name' => $name, 'completed_at' => now()]);

    $output = new BufferedOutput;
    $exitCode = Artisan::call('operations:forget', ['name' => $name, '--no-interaction' => true], $output);

    expect($exitCode)->toBe(1)
        ->and($output->fetch())->toContain('Command cancelled.')
        ->and(DB::table('operations')->pluck('name')->all())->toBe([$name]);
});

test('force bypasses confirmation in production and can forget a pruned operation', function () {
    app()->instance('env', 'production');
    $name = '2026_10_02_120000_removed';
    DB::table('operations')->insert(['name' => $name, 'completed_at' => now()]);

    artisan('operations:forget', ['name' => $name, '--force' => true, '--no-interaction' => true])
        ->assertSuccessful();

    expect(DB::table('operations')->count())->toBe(0)
        ->and(File::exists(config('operations.path')))->toBeFalse();

    app()->instance('env', 'testing');
});

test('forgetting fails when no completion record or checkpoints exist for the exact name', function (string $name) {
    DB::table('operations')->insert(['name' => '2026_10_02_120000_example', 'completed_at' => now()]);

    artisan('operations:forget', ['name' => $name, '--force' => true])
        ->expectsOutputToContain("No completion record or checkpoints found for operation [{$name}].")
        ->assertFailed();

    expect(DB::table('operations')->pluck('name')->all())->toBe(['2026_10_02_120000_example']);
})->with(['missing', 'example', '2026_10_02_120000_example.php']);
