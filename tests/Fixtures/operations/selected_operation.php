<?php

use DirectoryTree\Operations\Operation;
use Illuminate\Console\Command;

return new class extends Operation
{
    public function handle(Command $command): void
    {
        $command->info('Selected operation executed.');
    }
};
