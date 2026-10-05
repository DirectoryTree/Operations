<?php

namespace DirectoryTree\Operations\Concerns;

use DirectoryTree\Operations\OperationRepository;

trait HasCheckpoints
{
    /**
     * The operation's filename without the extension.
     */
    protected string $operationName;

    /**
     * The repository storing the operation's checkpoints.
     */
    protected OperationRepository $checkpointRepository;

    /**
     * Read a checkpoint or persist an array of checkpoint values.
     *
     * @param  string|array<string, mixed>  $key
     */
    public function checkpoint(string|array $key, mixed $default = null): mixed
    {
        if (is_array($key)) {
            $this->checkpointRepository->saveCheckpoints($this->operationName, $key);

            return null;
        }

        return $this->checkpointRepository->getCheckpoint($this->operationName, $key, $default);
    }

    /**
     * Set the operation's checkpoint context.
     */
    public function setCheckpointContext(string $name, OperationRepository $repository): static
    {
        $this->operationName = $name;
        $this->checkpointRepository = $repository;

        return $this;
    }
}
