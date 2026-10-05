<?php

use DirectoryTree\Operations\Operation;
use DirectoryTree\Operations\Tests\Fixtures\ExampleJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;

return new class extends Operation
{
    public function handle(Command $command): void
    {
        Bus::dispatch(new ExampleJob);
    }
};
