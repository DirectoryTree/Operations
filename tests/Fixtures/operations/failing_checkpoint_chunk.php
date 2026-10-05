<?php

use DirectoryTree\Operations\Concerns\HasCheckpoints;
use DirectoryTree\Operations\Operation;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

return new class extends Operation
{
    use HasCheckpoints;

    public function handle(Command $command): void
    {
        foreach ([1, 2] as $id) {
            DB::transaction(function () use ($id) {
                DB::table('examples')->insert(['id' => $id]);
                $this->checkpoints->put('last_id', $id);

                if ($id === 2) {
                    throw new RuntimeException('Chunk failed.');
                }
            });
        }
    }
};
