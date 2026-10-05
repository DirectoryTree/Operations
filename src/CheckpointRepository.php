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
        protected string $operation
    ) {}

    /**
     * Get a checkpoint from the writer connection.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $value = $this->connection->table('operation_checkpoints')
            ->useWritePdo()
            ->where('operation', $this->operation)
            ->where('name', $key)
            ->value('value');

        return $value === null ? value($default) : json_decode($value, true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * Persist checkpoint values without replacing other keys.
     *
     * @param  string|array<string, mixed>  $key
     */
    public function put(string|array $key, mixed $value = null): void
    {
        $values = is_array($key) ? $key : [$key => $value];

        $checkpoints = [];

        foreach ($values as $key => $value) {
            $checkpoints[] = [
                'operation' => $this->operation,
                'name' => $key,
                'value' => json_encode($value, JSON_THROW_ON_ERROR),
            ];
        }

        $this->connection->table('operation_checkpoints')
            ->upsert($checkpoints, ['operation', 'name'], ['value']);
    }

    /**
     * Delete the operation's checkpoints.
     */
    public function forget(): bool
    {
        return $this->connection->table('operation_checkpoints')
            ->where('operation', $this->operation)
            ->delete() > 0;
    }
}
