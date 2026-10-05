<?php

use DirectoryTree\Operations\CheckpointRepository;
use DirectoryTree\Operations\Concerns\HasCheckpoints;
use DirectoryTree\Operations\Contracts\WithinTransaction;
use DirectoryTree\Operations\Operation;
use DirectoryTree\Operations\OperationRepository;
use DirectoryTree\Operations\Tests\Fixtures\ExampleJob;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

test('operations receive checkpoints scoped to their registered name', function () {
    $repository = app(OperationRepository::class);
    $operation = new class extends Operation
    {
        use HasCheckpoints;

        public mixed $previous = null;

        public function handle(Command $command): void
        {
            $this->previous = $this->checkpoints->get('cursor');
            $this->checkpoints->put('cursor', 'next-page');
        }
    };

    $first = new CheckpointRepository(DB::connection(), 'first');
    $second = new CheckpointRepository(DB::connection(), 'second');
    $first->put('cursor', 'first-page');
    $second->put('cursor', 'second-page');

    $repository->run('first', $operation, new Command);
    expect($operation->previous)->toBe('first-page');

    $repository->run('second', $operation, new Command);
    expect($operation->previous)->toBe('second-page')
        ->and($first->get('cursor'))->toBe('next-page')
        ->and($second->get('cursor'))->toBe('next-page')
        ->and($repository->completed()->keys()->all())->toBe(['first', 'second']);
});

test('a failed chunk rolls back its work and checkpoint without losing earlier progress', function () {
    Schema::create('examples', function (Blueprint $table) {
        $table->id();
    });

    $operation = new class extends Operation
    {
        use HasCheckpoints;

        public function handle(Command $command): void
        {
            foreach ([1, 2] as $id) {
                DB::transaction(function () use ($id) {
                    DB::table('examples')->insert(['id' => $id]);
                    $this->checkpoints->put('last_id', $id);

                    if ($id === 2) {
                        throw new RuntimeException('Chunk failed.');
                    }
                });
            }
        }
    };

    expect(fn () => app(OperationRepository::class)->run('backfill', $operation, new Command))
        ->toThrow(RuntimeException::class, 'Chunk failed.');

    expect(DB::table('examples')->pluck('id')->all())->toBe([1])
        ->and((new CheckpointRepository(DB::connection(), 'backfill'))->get('last_id'))->toBe(1)
        ->and(DB::table('operations')->count())->toBe(0);
});

test('checkpoints participate in the whole operation transaction', function () {
    $checkpoints = new CheckpointRepository(DB::connection(), 'backfill');
    $checkpoints->put('last_id', 10);

    $operation = new class extends Operation implements WithinTransaction
    {
        use HasCheckpoints;

        public function handle(Command $command): void
        {
            $this->checkpoints->put(['last_id' => 20, 'finished' => true]);

            throw new RuntimeException('Backfill failed.');
        }
    };

    expect(fn () => app(OperationRepository::class)->run('backfill', $operation, new Command))
        ->toThrow(RuntimeException::class, 'Backfill failed.');

    expect($checkpoints->get('last_id'))->toBe(10)
        ->and($checkpoints->get('finished'))->toBeNull()
        ->and(DB::table('operation_checkpoints')->count())->toBe(1)
        ->and(DB::table('operations')->count())->toBe(0);
});

test('a transaction rolls back database changes when an operation fails', function () {
    Schema::create('examples', function (Blueprint $table) {
        $table->id();
    });

    $operation = new class extends Operation implements WithinTransaction
    {
        public function handle(Command $command): void
        {
            DB::table('examples')->insert(['id' => 1]);

            throw new RuntimeException('Backfill failed.');
        }
    };

    expect(fn () => app(OperationRepository::class)->run('backfill', $operation, new Command))
        ->toThrow(RuntimeException::class, 'Backfill failed.');

    expect(DB::table('examples')->count())->toBe(0)
        ->and(DB::table('operations')->count())->toBe(0);
});

test('a transaction rolls back the operation if recording completion fails', function () {
    Schema::create('examples', function (Blueprint $table) {
        $table->id();
    });

    $operation = new class extends Operation implements WithinTransaction
    {
        public function handle(Command $command): void
        {
            DB::table('examples')->insert(['id' => 1]);
            DB::table('operations')->insert(['name' => 'backfill', 'completed_at' => now()]);
        }
    };

    expect(fn () => app(OperationRepository::class)->run('backfill', $operation, new Command))
        ->toThrow(QueryException::class);

    expect(DB::table('examples')->count())->toBe(0)
        ->and(DB::table('operations')->count())->toBe(0);
});

test('successful transactional operations commit their changes and completion together', function () {
    Schema::create('examples', function (Blueprint $table) {
        $table->id();
    });

    $operation = new class extends Operation implements WithinTransaction
    {
        public function handle(Command $command): void
        {
            DB::table('examples')->insert(['id' => 1]);
        }
    };

    app(OperationRepository::class)->run('backfill', $operation, new Command);

    expect(DB::table('examples')->count())->toBe(1)
        ->and(DB::table('operations')->where('name', 'backfill')->value('completed_at'))->not->toBeNull()
        ->and(DB::transactionLevel())->toBe(0);
});

test('operations can dispatch jobs without becoming queued operations themselves', function () {
    Bus::fake();

    $operation = new class extends Operation
    {
        public function handle(Command $command): void
        {
            Bus::dispatch(new ExampleJob);
        }
    };

    app(OperationRepository::class)->run('dispatch', $operation, new Command);

    Bus::assertDispatched(ExampleJob::class);
    Bus::assertDispatchedTimes(ExampleJob::class, 1);

    expect(DB::table('operations')->where('name', 'dispatch')->value('completed_at'))->not->toBeNull();
});

test('forgetting clears checkpoints for completed and unfinished operations', function (bool $completed) {
    $checkpoints = new CheckpointRepository(DB::connection(), 'backfill');
    $other = new CheckpointRepository(DB::connection(), 'other');

    if ($completed) {
        DB::table('operations')->insert(['name' => 'backfill', 'completed_at' => now()]);
    }

    $checkpoints->put(['last_id' => 10, 'finished' => true]);
    $other->put('last_id', 20);

    $repository = app(OperationRepository::class);

    expect($repository->forget('backfill'))->toBeTrue()
        ->and($checkpoints->get('last_id', 0))->toBe(0)
        ->and($checkpoints->get('finished'))->toBeNull()
        ->and($other->get('last_id'))->toBe(20)
        ->and(DB::table('operations')->count())->toBe(0)
        ->and($repository->forget('backfill'))->toBeFalse();
})->with(['completed' => true, 'unfinished' => false]);

test('forgetting uses the configured operations connection', function () {
    config([
        'database.connections.operations' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ],
        'operations.connection' => 'operations',
    ]);

    foreach (File::glob(__DIR__.'/../../database/migrations/*.php') as $path) {
        File::getRequire($path)->up();
    }

    $name = '2026_10_02_120000_example';
    DB::table('operations')->insert(['name' => $name, 'completed_at' => now()]);
    DB::connection('operations')->table('operations')->insert(['name' => $name, 'completed_at' => now()]);
    DB::table('operation_checkpoints')->insert(['operation' => $name, 'key' => 'last_id', 'value' => '10']);
    DB::connection('operations')->table('operation_checkpoints')->insert(['operation' => $name, 'key' => 'last_id', 'value' => '20']);

    expect(app(OperationRepository::class)->forget($name))->toBeTrue();

    expect(DB::connection('operations')->table('operations')->count())->toBe(0)
        ->and(DB::connection('operations')->table('operation_checkpoints')->count())->toBe(0)
        ->and(DB::table('operations')->pluck('name')->all())->toBe([$name])
        ->and(DB::table('operation_checkpoints')->pluck('operation')->all())->toBe([$name]);
});
