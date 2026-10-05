<?php

use DirectoryTree\Operations\CheckpointRepository;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

test('checkpoint storage reports whether its table is installed', function () {
    $checkpoints = new CheckpointRepository(DB::connection(), 'backfill');

    expect($checkpoints->installed())->toBeTrue();

    Schema::drop('operation_checkpoints');

    expect($checkpoints->installed())->toBeFalse();
});

test('using checkpoints without their migration raises a database exception', function (string $method, array $arguments) {
    Schema::drop('operation_checkpoints');

    $checkpoints = new CheckpointRepository(DB::connection(), 'backfill');

    expect(fn () => $checkpoints->{$method}(...$arguments))
        ->toThrow(QueryException::class, 'operation_checkpoints');
})->with([
    'read' => ['get', ['cursor']],
    'write' => ['put', ['cursor', 'next-page']],
    'forget' => ['forget', []],
]);

test('checkpoints read defaults and persist JSON values while preserving other keys', function (bool $multiple) {
    $checkpoints = new CheckpointRepository(DB::connection(), 'backfill');
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

    expect($checkpoints->get('missing'))->toBeNull()
        ->and($checkpoints->get('missing', 42))->toBe(42);

    if ($multiple) {
        $checkpoints->put($values);
    } else {
        foreach ($values as $key => $value) {
            $checkpoints->put($key, $value);
        }
    }

    $checkpoints->put('cursor', 'last-page');
    $checkpoints->put([]);
    $values['cursor'] = 'last-page';

    foreach ($values as $key => $value) {
        expect($checkpoints->get($key, 'missing'))->toBe($value);
    }

    expect(DB::table('operation_checkpoints')->count())->toBe(count($values))
        ->and(DB::table('operation_checkpoints')->where('key', 'missing')->exists())->toBeFalse();
})->with(['multiple values' => true, 'single values' => false]);

test('checkpoint values are isolated between operations', function () {
    $first = new CheckpointRepository(DB::connection(), 'first');
    $second = new CheckpointRepository(DB::connection(), 'second');

    $first->put('cursor', 'first-page');

    expect($second->get('cursor'))->toBeNull();

    $second->put('cursor', 'second-page');

    expect($first->get('cursor'))->toBe('first-page')
        ->and($second->get('cursor'))->toBe('second-page');
});

test('forgetting checkpoints preserves other operations and completion records', function () {
    $first = new CheckpointRepository(DB::connection(), 'first');
    $second = new CheckpointRepository(DB::connection(), 'second');

    DB::table('operations')->insert(['name' => 'first', 'completed_at' => now()]);
    $first->put(['cursor' => 'first-page', 'processed' => 10]);
    $second->put('cursor', 'second-page');

    expect($first->forget())->toBeTrue()
        ->and($first->forget())->toBeFalse()
        ->and($first->get('cursor'))->toBeNull()
        ->and($first->get('processed'))->toBeNull()
        ->and($second->get('cursor'))->toBe('second-page')
        ->and(DB::table('operations')->pluck('name')->all())->toBe(['first']);
});

test('invalid JSON values fail before any checkpoints are changed', function () {
    $checkpoints = new CheckpointRepository(DB::connection(), 'backfill');
    $checkpoints->put('last_id', 10);

    expect(fn () => $checkpoints->put(['last_id' => 20, 'invalid' => NAN]))->toThrow(JsonException::class);

    expect($checkpoints->get('last_id'))->toBe(10)
        ->and($checkpoints->get('invalid'))->toBeNull()
        ->and(DB::table('operation_checkpoints')->count())->toBe(1);
});
