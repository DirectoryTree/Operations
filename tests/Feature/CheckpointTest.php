<?php

use Illuminate\Database\QueryException;
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
    File::put(config('operations.path')."/{$name}.php", <<<'PHP'
    <?php

    return new class extends \DirectoryTree\Operations\Operation {
        public function handle(\Illuminate\Console\Command $command): void
        {
            $command->info('Operation executed.');
        }
    };
    PHP);

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

test('using checkpoints without their migration raises a database exception', function (string $method) {
    Schema::drop('operation_checkpoints');
    app()->instance('checkpoint_method', $method);

    File::ensureDirectoryExists(config('operations.path'));
    File::put(config('operations.path').'/2026_10_05_120000_example.php', <<<'PHP'
    <?php

    return new class extends \DirectoryTree\Operations\Operation {
        use \DirectoryTree\Operations\Concerns\HasCheckpoints;

        public function handle(\Illuminate\Console\Command $command): void
        {
            $this->checkpoints->{app('checkpoint_method')}('cursor', 'next-page');
        }
    };
    PHP);

    expect(fn () => artisan('operations:run')->run())->toThrow(QueryException::class, 'operation_checkpoints');

    expect(DB::table('operations')->count())->toBe(0);
})->with([
    'read' => 'get',
    'write' => 'put',
]);

test('checkpoints read defaults and persist JSON values while preserving other keys', function (bool $multiple) {
    $values = [
        'cursor' => 'next-page',
        'count' => 0,
        'ratio' => 1.5,
        'enabled' => false,
        'empty' => '',
        'nullable' => null,
        'ids' => [1, 2, 3],
        'metadata' => ['page' => 2, 'finished' => false],
    ];

    app()->instance('checkpoint_values', $values);
    app()->instance('multiple', $multiple);
    app()->instance('observed', collect());

    File::ensureDirectoryExists(config('operations.path'));
    File::put(config('operations.path').'/2026_10_05_120000_example.php', <<<'PHP'
    <?php

    return new class extends \DirectoryTree\Operations\Operation {
        use \DirectoryTree\Operations\Concerns\HasCheckpoints;

        public function handle(\Illuminate\Console\Command $command): void
        {
            app('observed')->push($this->checkpoints->get('missing'));
            app('observed')->push($this->checkpoints->get('missing', 42));

            if (app('multiple')) {
                $this->checkpoints->put(app('checkpoint_values'));
            } else {
                foreach (app('checkpoint_values') as $key => $value) {
                    $this->checkpoints->put($key, $value);
                }
            }

            $this->checkpoints->put('cursor', 'last-page');
            $this->checkpoints->put([]);

            foreach (app('checkpoint_values') as $key => $value) {
                app('observed')->push($this->checkpoints->get($key, 'missing'));
            }
        }
    };
    PHP);

    artisan('operations:run')->assertSuccessful();

    $values['cursor'] = 'last-page';

    expect(app('observed')->all())->toBe([null, 42, ...array_values($values)])
        ->and(DB::table('operation_checkpoints')->count())->toBe(count($values))
        ->and(DB::table('operation_checkpoints')->where('name', 'missing')->exists())->toBeFalse();
})->with(['multiple values' => true, 'single values' => false]);

test('a failed operation resumes from its saved checkpoint on the next run', function () {
    Schema::create('examples', function (Blueprint $table) {
        $table->id();
    });

    app()->instance('should_fail', true);

    File::ensureDirectoryExists(config('operations.path'));
    File::put(config('operations.path').'/2026_10_05_120000_backfill.php', <<<'PHP'
    <?php

    return new class extends \DirectoryTree\Operations\Operation {
        use \DirectoryTree\Operations\Concerns\HasCheckpoints;

        public function handle(\Illuminate\Console\Command $command): void
        {
            for ($id = $this->checkpoints->get('last_id', 0) + 1; $id <= 3; $id++) {
                if ($id === 3 && app('should_fail')) {
                    throw new \RuntimeException('Backfill interrupted.');
                }

                \Illuminate\Support\Facades\DB::table('examples')->insert(['id' => $id]);
                $this->checkpoints->put('last_id', $id);
            }
        }
    };
    PHP);

    expect(fn () => artisan('operations:run')->run())->toThrow(RuntimeException::class, 'Backfill interrupted.');

    expect(DB::table('examples')->pluck('id')->all())->toBe([1, 2])
        ->and(json_decode(DB::table('operation_checkpoints')->value('value')))->toBe(2)
        ->and(DB::table('operations')->count())->toBe(0);

    app()->instance('should_fail', false);

    artisan('operations:run')->assertSuccessful();
    artisan('operations:run')->expectsOutputToContain('No pending operations.')->assertSuccessful();

    expect(DB::table('examples')->pluck('id')->all())->toBe([1, 2, 3])
        ->and(json_decode(DB::table('operation_checkpoints')->value('value')))->toBe(3)
        ->and(DB::table('operations')->count())->toBe(1);
});

test('checkpoint names are scoped to the operation filename', function () {
    app()->instance('observed', collect());

    File::ensureDirectoryExists(config('operations.path'));

    foreach (['2026_10_05_120000_first', '2026_10_05_120001_second'] as $name) {
        File::put(config('operations.path')."/{$name}.php", <<<'PHP'
        <?php

        return new class extends \DirectoryTree\Operations\Operation {
            use \DirectoryTree\Operations\Concerns\HasCheckpoints;

            public function handle(\Illuminate\Console\Command $command): void
            {
                app('observed')->push($this->checkpoints->get('cursor'));
                $this->checkpoints->put('cursor', basename(__FILE__, '.php'));
                app('observed')->push($this->checkpoints->get('cursor'));
            }
        };
        PHP);
    }

    artisan('operations:run')->assertSuccessful();

    expect(app('observed')->all())->toBe([
        null, '2026_10_05_120000_first',
        null, '2026_10_05_120001_second',
    ])->and(DB::table('operation_checkpoints')->count())->toBe(2);
});

test('a failed chunk rolls back its work and checkpoint without losing earlier progress', function () {
    Schema::create('examples', function (Blueprint $table) {
        $table->id();
    });

    File::ensureDirectoryExists(config('operations.path'));
    File::put(config('operations.path').'/2026_10_05_120000_backfill.php', <<<'PHP'
    <?php

    return new class extends \DirectoryTree\Operations\Operation {
        use \DirectoryTree\Operations\Concerns\HasCheckpoints;

        public function handle(\Illuminate\Console\Command $command): void
        {
            foreach ([1, 2] as $id) {
                \Illuminate\Support\Facades\DB::transaction(function () use ($id) {
                    \Illuminate\Support\Facades\DB::table('examples')->insert(['id' => $id]);
                    $this->checkpoints->put('last_id', $id);

                    if ($id === 2) {
                        throw new \RuntimeException('Chunk failed.');
                    }
                });
            }
        }
    };
    PHP);

    expect(fn () => artisan('operations:run')->run())->toThrow(RuntimeException::class, 'Chunk failed.');

    expect(DB::table('examples')->pluck('id')->all())->toBe([1])
        ->and(json_decode(DB::table('operation_checkpoints')->value('value')))->toBe(1)
        ->and(DB::table('operations')->count())->toBe(0);
});

test('checkpoints participate in the whole operation transaction', function () {
    $name = '2026_10_05_120000_backfill';

    DB::table('operation_checkpoints')->insert([
        'operation' => $name,
        'name' => 'last_id',
        'value' => '10',
    ]);

    File::ensureDirectoryExists(config('operations.path'));
    File::put(config('operations.path')."/{$name}.php", <<<'PHP'
    <?php

    return new class extends \DirectoryTree\Operations\Operation implements \DirectoryTree\Operations\Contracts\WithinTransaction {
        use \DirectoryTree\Operations\Concerns\HasCheckpoints;

        public function handle(\Illuminate\Console\Command $command): void
        {
            $this->checkpoints->put(['last_id' => 20, 'finished' => true]);

            throw new \RuntimeException('Backfill failed.');
        }
    };
    PHP);

    expect(fn () => artisan('operations:run')->run())->toThrow(RuntimeException::class, 'Backfill failed.');

    expect(DB::table('operation_checkpoints')->count())->toBe(1)
        ->and(json_decode(DB::table('operation_checkpoints')->value('value')))->toBe(10)
        ->and(DB::table('operations')->count())->toBe(0);
});

test('invalid JSON values fail before any checkpoints are changed', function () {
    File::ensureDirectoryExists(config('operations.path'));
    File::put(config('operations.path').'/2026_10_05_120000_example.php', <<<'PHP'
    <?php

    return new class extends \DirectoryTree\Operations\Operation {
        use \DirectoryTree\Operations\Concerns\HasCheckpoints;

        public function handle(\Illuminate\Console\Command $command): void
        {
            $this->checkpoints->put('last_id', 10);
            $this->checkpoints->put(['last_id' => 20, 'invalid' => NAN]);
        }
    };
    PHP);

    expect(fn () => artisan('operations:run')->run())->toThrow(JsonException::class);

    expect(DB::table('operation_checkpoints')->count())->toBe(1)
        ->and(json_decode(DB::table('operation_checkpoints')->value('value')))->toBe(10)
        ->and(DB::table('operations')->count())->toBe(0);
});

test('checkpoints use the configured connection and read from its writer', function () {
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

    app()->instance('observed', collect());

    File::put(config('operations.path').'/2026_10_05_120000_example.php', <<<'PHP'
    <?php

    return new class extends \DirectoryTree\Operations\Operation {
        use \DirectoryTree\Operations\Concerns\HasCheckpoints;

        public function handle(\Illuminate\Console\Command $command): void
        {
            app('observed')->push($this->checkpoints->get('cursor', 'initial'));
            $this->checkpoints->put('cursor', 'next-page');
            app('observed')->push($this->checkpoints->get('cursor'));
        }
    };
    PHP);

    artisan('operations:run')->assertSuccessful();

    expect(app('observed')->all())->toBe(['initial', 'next-page'])
        ->and(DB::connection('operations')->table('operation_checkpoints')->useWritePdo()->count())->toBe(1)
        ->and(DB::table('operation_checkpoints')->count())->toBe(0);

    config(['operations.connection' => null]);
});
