<?php

namespace DirectoryTree\Operations;

use Illuminate\Console\Command;

abstract class Operation
{
    /**
     * Run the operation.
     */
    abstract public function handle(Command $command): void;
}
