<?php

use DirectoryTree\Operations\Operation;
use Illuminate\Console\Command;

return new class extends Operation
{
    public function handle(Command $command): void
    {
        $command->info('Backfilling company names...');
        $command->table(['Updated'], [[1]]);

        $output = $command->getOutput();
        $output->progressStart(1);
        $output->progressAdvance();
        $output->progressFinish();
    }
};
