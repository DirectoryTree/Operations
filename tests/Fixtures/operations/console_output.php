<?php

use DirectoryTree\Operations\Operation;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

return new class extends Operation
{
    public function handle(Command $command): void
    {
        $command->info('Backfilling company names...');
        $command->table(['Updated'], [[2]]);

        $output = $command->getOutput();
        $output->progressStart(2);
        $output->progressAdvance(2);
        $output->progressFinish();

        $command->info('Company names updated.');
        app()->instance('forced', $command->option('force'));
        app()->instance('completed_during_operation', DB::table('operations')->count());
    }
};
