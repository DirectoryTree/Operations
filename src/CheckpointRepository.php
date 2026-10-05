<?php

namespace DirectoryTree\Operations;

use Illuminate\Database\ConnectionInterface;

class CheckpointRepository
{
    /**
     * Create a new checkpoint repository.
     */
    public function __construct(
        protected ConnectionInterface $connection
    ) {}

    /**
     * Get a checkpoint from the writer connection.
     */
    public function get(string $operation, string $name, mixed $default = null): mixed
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
    public function save(string $operation, array $values): void
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
     * Delete an operation's checkpoints when checkpoint storage is installed.
     */
    public function forget(string $operation): bool
    {
        if (! $this->connection->getSchemaBuilder()->hasTable('operation_checkpoints')) {
            return false;
        }

        return $this->connection->table('operation_checkpoints')
            ->where('operation', $operation)
            ->delete() > 0;
    }
}
