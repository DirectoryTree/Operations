<?php

use DirectoryTree\Operations\Operation;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\File;

use function Pest\Laravel\artisan;

test('the generator creates a timestamped anonymous operation in the configured directory', function () {
    Date::setTestNow('2026-10-02 12:30:45');

    artisan('make:operation', ['name' => 'BackfillCompanyNames'])->assertSuccessful();

    $path = config('operations.path').'/2026_10_02_123045_backfill_company_names.php';

    expect(File::exists($path))->toBeTrue()
        ->and(File::getRequire($path))->toBeInstanceOf(Operation::class);
});

test('the generator does not overwrite an existing operation', function () {
    Date::setTestNow('2026-10-02 12:30:45');

    File::ensureDirectoryExists(config('operations.path'));
    $path = config('operations.path').'/2026_10_02_123045_backfill.php';
    File::put($path, 'Existing operation.');

    artisan('make:operation', ['name' => 'backfill'])->assertFailed();

    expect(File::get($path))->toBe('Existing operation.');
});

test('the generator rejects paths and empty names', function (string $name) {
    artisan('make:operation', ['name' => $name])->assertFailed();

    expect(File::exists(config('operations.path')))->toBeFalse();
})->with(['../outside', '/tmp/outside', '', 'bad.php']);
