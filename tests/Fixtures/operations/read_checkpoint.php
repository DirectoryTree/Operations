<?php

use DirectoryTree\Operations\Concerns\HasCheckpoints;
use DirectoryTree\Operations\Operation;
use Illuminate\Console\Command;

return new class extends Operation
{
    use HasCheckpoints;

    public function handle(Command $command): void
    {
        app('observed')->push($this->checkpoints->get('last_id', 0));
    }
};
