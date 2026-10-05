<?php

use DirectoryTree\Operations\Concerns\HasCheckpoints;
use DirectoryTree\Operations\Operation;
use Illuminate\Console\Command;

return new class extends Operation
{
    use HasCheckpoints;

    public function handle(Command $command): void
    {
        app('observed')->push($this->checkpoints->get('missing'));
        app('observed')->push($this->checkpoints->get('missing', 42));

        if (app('multiple')) {
            $this->checkpoints->put(app('checkpoint_values'));
        } else {
            foreach (app('checkpoint_values') as $key => $value) {
                $this->checkpoints->put($key, $value);
            }
        }

        $this->checkpoints->put('cursor', 'last-page');
        $this->checkpoints->put([]);

        foreach (app('checkpoint_values') as $key => $value) {
            app('observed')->push($this->checkpoints->get($key, 'missing'));
        }
    }
};
