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
     * Get a checkpoint from the writer connection.
     */
    public function getCheckpoint(string $operation, string $name, mixed $default = null): mixed
    {
        $value = $this->connection->table('operation_checkpoints')
            ->useWritePdo()
            ->where('operation', $operation)
            ->where('name', $name)
            ->value('value');

        return $value === null ? value($default) : json_decode($value, true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * Persist checkpoint values without replacing other keys.
     *
     * @param  array<string, mixed>  $values
     */
    public function saveCheckpoints(string $operation, array $values): void
    {
        $checkpoints = [];

        foreach ($values as $name => $value) {
            $checkpoints[] = [
                'operation' => $operation,
                'name' => $name,
                'value' => json_encode($value, JSON_THROW_ON_ERROR),
            ];
        }

        $this->connection->table('operation_checkpoints')
            ->upsert($checkpoints, ['operation', 'name'], ['value']);
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

            if ($this->connection->getSchemaBuilder()->hasTable('operation_checkpoints')) {
                $deleted += $this->connection->table('operation_checkpoints')
                    ->where('operation', $name)
                    ->delete();
            }

            return $deleted > 0;
        });
    }

    /**
     * Execute an operation and record its successful completion.
     */
    public function run(string $name, Operation $operation, Command $command): void
    {
        if (in_array(HasCheckpoints::class, class_uses_recursive($operation))) {
            $operation->setCheckpointContext($name, $this);
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
}
