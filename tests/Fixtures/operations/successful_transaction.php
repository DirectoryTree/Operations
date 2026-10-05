<?php

use DirectoryTree\Operations\Contracts\WithinTransaction;
use DirectoryTree\Operations\Operation;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

return new class extends Operation implements WithinTransaction
{
    public function handle(Command $command): void
    {
        DB::table('examples')->insert(['id' => 1]);
    }
};
