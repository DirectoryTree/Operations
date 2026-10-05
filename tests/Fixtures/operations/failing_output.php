<?php

use DirectoryTree\Operations\Contracts\WithinTransaction;
use DirectoryTree\Operations\Operation;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

return new class extends Operation implements WithinTransaction
{
    public function handle(Command $command): void
    {
        $command->info('Starting the backfill...');

        if (app('failure') === 'operation') {
            throw new RuntimeException('Backfill failed.');
        }

        // Cause the runner's completion insert to fail on the unique name.
        DB::table('operations')->insert([
            'name' => basename(__FILE__, '.php'),
            'completed_at' => now(),
        ]);
    }
};
