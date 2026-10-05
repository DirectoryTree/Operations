<?php

use DirectoryTree\Operations\Operation;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

return new class extends Operation
{
    public function handle(Command $command): void
    {
        app('executed')->push(basename(__FILE__, '.php'));
        app('ledger_counts')->push(DB::table('operations')->count());
    }
};
