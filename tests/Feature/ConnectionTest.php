<?php

use DirectoryTree\Operations\Concerns\HasCheckpoints;
use DirectoryTree\Operations\Operation;
use DirectoryTree\Operations\OperationRepository;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

test('the migration and repository use the configured ledger connection', function () {
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

    $operation = new class extends Operation
    {
        public function handle(Command $command): void {}
    };

    $repository = app(OperationRepository::class);
    $repository->run('example', $operation, new Command);

    expect($repository->completed()->keys()->all())->toBe(['example'])
        ->and(DB::connection('operations')->table('operations')->count())->toBe(1)
        ->and(DB::table('operations')->count())->toBe(0);

    $migration->down();

    expect(Schema::connection('operations')->hasTable('operations'))->toBeFalse()
        ->and(Schema::hasTable('operations'))->toBeTrue();

    config(['operations.connection' => null]);
});

test('repositories use the configured connection and read from its writer', function () {
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

    foreach (File::glob(__DIR__.'/../../database/migrations/*.php') as $path) {
        File::getRequire($path)->up();
    }

    $operation = new class extends Operation
    {
        use HasCheckpoints;

        public array $observed = [];

        public function handle(Command $command): void
        {
            $this->observed[] = $this->checkpoints->get('cursor', 'initial');
            $this->checkpoints->put('cursor', 'next-page');
            $this->observed[] = $this->checkpoints->get('cursor');
        }
    };

    $repository = app(OperationRepository::class);
    $repository->run('example', $operation, new Command);

    expect($operation->observed)->toBe(['initial', 'next-page'])
        ->and($repository->completed()->keys()->all())->toBe(['example'])
        ->and(DB::connection('operations')->table('operation_checkpoints')->useWritePdo()->count())->toBe(1)
        ->and(DB::table('operations')->count())->toBe(0)
        ->and(DB::table('operation_checkpoints')->count())->toBe(0);

    expect($repository->forget('example'))->toBeTrue()
        ->and($repository->completed()->all())->toBe([])
        ->and(DB::connection('operations')->table('operation_checkpoints')->useWritePdo()->count())->toBe(0);

    config(['operations.connection' => null]);
});
