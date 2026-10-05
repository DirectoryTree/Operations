<?php

namespace DirectoryTree\Operations;

use Illuminate\Database\ConnectionInterface;

class CheckpointRepository
{
    /**
     * Create a new checkpoint repository.
     */
    public function __construct(
        protected ConnectionInterface $connection,
        protected string $name
    ) {}

    /**
     * Get a checkpoint from the writer connection.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $value = $this->connection->table('operation_checkpoints')
            ->useWritePdo()
            ->where('operation', $this->name)
            ->where('name', $key)
            ->value('value');

        return $value === null ? value($default) : json_decode($value, true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * Persist checkpoint values without replacing other keys.
     *
     * @param  array<string, mixed>  $values
     */
    public function save(array $values): void
    {
        $checkpoints = [];

        foreach ($values as $key => $value) {
            $checkpoints[] = [
                'operation' => $this->name,
                'name' => $key,
                'value' => json_encode($value, JSON_THROW_ON_ERROR),
            ];
        }

        $this->connection->table('operation_checkpoints')
            ->upsert($checkpoints, ['operation', 'name'], ['value']);
    }

    /**
     * Delete an operation's checkpoints when checkpoint storage is installed.
     */
    public function forget(): bool
    {
        if (! $this->connection->getSchemaBuilder()->hasTable('operation_checkpoints')) {
            return false;
        }

        return $this->connection->table('operation_checkpoints')
            ->where('operation', $this->name)
            ->delete() > 0;
    }
}
