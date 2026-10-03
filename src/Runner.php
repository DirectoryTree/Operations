<?php

namespace DirectoryTree\Operations;

use Closure;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use InvalidArgumentException;
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
    public function run(Command $command, ?Closure $before = null, ?Closure $after = null, ?string $operation = null): int
    {
        $files = $operation === null ? $this->files() : $this->find($operation);
        $completed = $this->repository->completed();
        $count = 0;

        foreach ($files as $name => $path) {
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

    /**
     * Find an operation by its exact filename without the extension.
     *
     * @return array<string, string>
     */
    protected function find(string $name): array
    {
        if (! preg_match('/\A[a-zA-Z0-9_-]+\z/', $name)) {
            throw new InvalidArgumentException('Use an exact operation filename without .php, containing only letters, numbers, underscores, and hyphens.');
        }

        $files = $this->files();

        if (! isset($files[$name])) {
            throw new InvalidArgumentException("Operation [{$name}] not found. Use its exact filename without .php.");
        }

        return [$name => $files[$name]];
    }
}
