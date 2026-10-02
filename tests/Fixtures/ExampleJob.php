<?php

namespace DirectoryTree\Operations\Tests\Fixtures;

use Illuminate\Contracts\Queue\ShouldQueue;

class ExampleJob implements ShouldQueue
{
    public function handle(): void {}
}
