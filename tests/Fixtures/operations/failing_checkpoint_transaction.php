<?php

use DirectoryTree\Operations\Concerns\HasCheckpoints;
use DirectoryTree\Operations\Contracts\WithinTransaction;
use DirectoryTree\Operations\Operation;
use Illuminate\Console\Command;

return new class extends Operation implements WithinTransaction
{
    use HasCheckpoints;

    public function handle(Command $command): void
    {
        $this->checkpoints->put(['last_id' => 20, 'finished' => true]);

        throw new RuntimeException('Backfill failed.');
    }
};
