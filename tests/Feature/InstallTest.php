<?php

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\File;

use function Pest\Laravel\artisan;

test('the migrations can be published with a current timestamp', function (string $table) {
    Date::setTestNow('2026-10-02 13:45:00');

    artisan('vendor:publish', ['--tag' => 'operations-migrations'])->assertSuccessful();

    $files = File::glob(database_path("migrations/*_create_{$table}_table.php"));

    expect($files)->toHaveCount(1)
        ->and(basename($files[0]))->toStartWith('2026_10_02_1345');
})->with(['operations', 'operation_checkpoints']);

test('the package configuration can be published', function () {
    artisan('vendor:publish', ['--tag' => 'operations-config'])->assertSuccessful();

    expect(File::get(config_path('operations.php')))->toBe(File::get(__DIR__.'/../../config/operations.php'));
});
