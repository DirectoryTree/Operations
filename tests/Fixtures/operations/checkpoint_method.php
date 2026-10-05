<?php

use DirectoryTree\Operations\Concerns\HasCheckpoints;
use DirectoryTree\Operations\Operation;
use Illuminate\Console\Command;

return new class extends Operation
{
    use HasCheckpoints;

    public function handle(Command $command): void
    {
        $this->checkpoints->{app('checkpoint_method')}(...app('checkpoint_arguments'));
    }
};
