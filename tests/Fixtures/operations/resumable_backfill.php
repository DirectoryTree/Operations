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
        for ($id = $this->checkpoints->get('last_id', 0) + 1; $id <= 3; $id++) {
            if ($id === 3 && app('should_fail')) {
                throw new RuntimeException('Backfill interrupted.');
            }

            DB::table('examples')->insert(['id' => $id]);
            $this->checkpoints->put('last_id', $id);
        }
    }
};
