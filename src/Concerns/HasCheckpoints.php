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
     * Set the operation's checkpoint repository.
     */
    public function setCheckpoints(CheckpointRepository $checkpoints): static
    {
        $this->checkpoints = $checkpoints;

        return $this;
    }
}
