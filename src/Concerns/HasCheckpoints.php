<?php

namespace DirectoryTree\Operations\Concerns;

use DirectoryTree\Operations\CheckpointRepository;

trait HasCheckpoints
{
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
            $this->checkpoints->save($key);

            return null;
        }

        return $this->checkpoints->get($key, $default);
    }

    /**
     * Set the operation's checkpoint repository.
     */
    public function setCheckpoints(CheckpointRepository $checkpoints): static
    {
        $this->checkpoints = $checkpoints;

        return $this;
    }
}
