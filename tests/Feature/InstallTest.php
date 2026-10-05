<?php

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\File;

use function Pest\Laravel\artisan;

test('the standard migration can be published without opting into checkpoints', function () {
    Date::setTestNow('2026-10-02 13:45:00');

    artisan('vendor:publish', ['--tag' => 'operations-migrations'])->assertSuccessful();

    $files = File::glob(database_path('migrations/*_create_operations_table.php'));

    expect($files)->toHaveCount(1)
        ->and(basename($files[0]))->toStartWith('2026_10_02_1345')
        ->and(File::glob(database_path('migrations/*_create_operation_checkpoints_table.php')))->toBe([]);
});

test('the package configuration can be published', function () {
    artisan('vendor:publish', ['--tag' => 'operations-config'])->assertSuccessful();

    expect(File::get(config_path('operations.php')))->toBe(File::get(__DIR__.'/../../config/operations.php'));
});

test('existing installations can publish only the checkpoint migration', function () {
    Date::setTestNow('2026-10-05 12:00:00');

    File::ensureDirectoryExists(database_path('migrations'));
    $original = database_path('migrations/2026_10_02_120000_create_operations_table.php');
    File::copy(__DIR__.'/../../database/migrations/2026_10_02_165413_create_operations_table.php', $original);

    artisan('vendor:publish', ['--tag' => 'operations-checkpoints-migration'])->assertSuccessful();

    $files = File::glob(database_path('migrations/*_create_operation_checkpoints_table.php'));

    expect(File::glob(database_path('migrations/*_create_operations_table.php')))->toBe([$original])
        ->and($files)->toHaveCount(1)
        ->and(basename($files[0]))->toStartWith('2026_10_05_1200');
});
