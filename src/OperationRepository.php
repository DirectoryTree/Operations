<?php

namespace DirectoryTree\Operations;

use DirectoryTree\Operations\Concerns\HasCheckpoints;
use DirectoryTree\Operations\Contracts\WithinTransaction;
use Illuminate\Console\Command;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Collection;

class OperationRepository
{
    /**
     * Create a new operation repository.
     */
    public function __construct(
        protected ConnectionInterface $connection
    ) {}

    /**
     * Get the completion timestamps, keyed by operation name.
     *
     * @return Collection<string, string>
     */
    public function completed(): Collection
    {
        return $this->connection->table('operations')
            ->useWritePdo()
            ->pluck('completed_at', 'name');
    }

    /**
     * Delete the completion record and checkpoints for the given operation.
     */
    public function forget(string $name): bool
    {
        return $this->connection->transaction(function () use ($name) {
            $deleted = $this->connection->table('operations')
                ->where('name', $name)
                ->delete();

            $forgotten = $this->connection->getSchemaBuilder()->hasTable('operation_checkpoints')
                && $this->checkpoints($name)->forget();

            return $deleted > 0 || $forgotten;
        });
    }

    /**
     * Execute an operation and record its successful completion.
     */
    public function run(string $name, Operation $operation, Command $command): void
    {
        if (in_array(HasCheckpoints::class, class_uses_recursive($operation))) {
            $operation->setCheckpoints($this->checkpoints($name));
        }

        $run = function () use ($name, $operation, $command) {
            $operation->handle($command);

            $this->connection->table('operations')->insert([
                'name' => $name,
                'completed_at' => now(),
            ]);
        };

        if ($operation instanceof WithinTransaction) {
            $this->connection->transaction($run);
        } else {
            $run();
        }
    }

    /**
     * Create a checkpoint repository for the given operation.
     */
    protected function checkpoints(string $name): CheckpointRepository
    {
        return new CheckpointRepository($this->connection, $name);
    }
}
