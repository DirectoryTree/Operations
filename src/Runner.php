<?php

namespace DirectoryTree\Operations;

use Closure;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use UnexpectedValueException;

class Runner
{
    /**
     * Create a new operation runner.
     */
    public function __construct(
        protected Filesystem $files,
        protected OperationRepository $repository
    ) {}

    /**
     * Get the operation paths, keyed and sorted by filename.
     *
     * @return array<string, string>
     */
    public function files(): array
    {
        $operations = [];

        foreach ($this->files->glob(rtrim(config('operations.path'), '/').'/*.php') as $file) {
            $operations[pathinfo($file, PATHINFO_FILENAME)] = $file;
        }

        ksort($operations);

        return $operations;
    }

    /**
     * Run pending operations in filename order.
     *
     * @param  (Closure(string): void)|null  $before
     * @param  (Closure(string, float): void)|null  $after
     */
    public function run(Command $command, ?Closure $before = null, ?Closure $after = null): int
    {
        $completed = $this->repository->completed();
        $count = 0;

        foreach ($this->files() as $name => $path) {
            if ($completed->has($name)) {
                continue;
            }

            $operation = $this->files->getRequire($path);

            if (! $operation instanceof Operation) {
                throw new UnexpectedValueException("Operation [{$name}] must return an instance of ".Operation::class.'.');
            }

            $before?->__invoke($name);

            $startedAt = hrtime(true);

            $this->repository->run($name, $operation, $command);

            $count++;

            $after?->__invoke($name, (hrtime(true) - $startedAt) / 1e9);
        }

        return $count;
    }
}
