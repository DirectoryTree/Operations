<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

use function Pest\Laravel\artisan;

test('status includes pending operations and completed operations whose files were removed', function () {
    File::ensureDirectoryExists(config('operations.path'));
    File::copy(
        __DIR__.'/../Fixtures/operations/throw_on_load.php',
        config('operations.path').'/2026_10_02_120002_pending.php',
    );
    DB::table('operations')->insert([
        'name' => '2026_10_02_120001_completed',
        'completed_at' => '2026-10-02 12:00:00',
    ]);

    artisan('operations:status')->expectsTable(
        ['Operation', 'Status', 'Completed at', 'File'],
        [
            ['2026_10_02_120001_completed', 'Completed', '2026-10-02 12:00:00', 'Missing'],
            ['2026_10_02_120002_pending', 'Pending', '—', 'Present'],
        ],
    )->assertSuccessful();
});

test('status reports an empty installation', function () {
    artisan('operations:status')->expectsOutputToContain('No operations found.')->assertSuccessful();
});
