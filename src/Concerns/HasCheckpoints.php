<?php

namespace DirectoryTree\Operations\Concerns;

use DirectoryTree\Operations\CheckpointRepository;

trait HasCheckpoints
{
    /**
     * The operation's filename without the extension.
     */
    protected string $name;

    /**
     * The repository storing the operation's checkpoints.
     */
    protected CheckpointRepository $checkpoints;

    /**
     * Read a checkpoint or persist an array of checkpoint values.
     *
     * @param  string|array<string, mixed>  $key
     */
    public function checkpoint(string|array $key, mixed $default = null): mixed
    {
        if (is_array($key)) {
            $this->checkpoints->save($this->name, $key);

            return null;
        }

        return $this->checkpoints->get($this->name, $key, $default);
    }

    /**
     * Set the operation's checkpoint context.
     */
    public function setCheckpointContext(string $name, CheckpointRepository $repository): static
    {
        $this->name = $name;
        $this->checkpoints = $repository;

        return $this;
    }
}
