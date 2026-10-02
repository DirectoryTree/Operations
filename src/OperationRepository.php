<?php

namespace DirectoryTree\Operations;

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
     * Execute an operation and record its successful completion.
     */
    public function run(string $name, Operation $operation, Command $command): void
    {
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
}
