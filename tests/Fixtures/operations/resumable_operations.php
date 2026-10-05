<?php

use DirectoryTree\Operations\Operation;
use Illuminate\Console\Command;

return new class extends Operation
{
    public function handle(Command $command): void
    {
        $name = basename(__FILE__, '.php');
        app('attempts')->push($name);

        if (str_ends_with($name, 'second') && app('should_fail')) {
            throw new RuntimeException('Backfill failed.');
        }
    }
};
