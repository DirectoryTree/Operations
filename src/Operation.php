<?php

namespace DirectoryTree\Operations;

use Illuminate\Console\Command;

abstract class Operation
{
    /**
     * The operation's filename without the extension.
     */
    protected string $name;

    /**
     * The repository executing the operation.
     */
    protected OperationRepository $repository;

    /**
     * Run the operation.
     */
    abstract public function handle(Command $command): void;

    /**
     * Read a checkpoint or persist an array of checkpoint values.
     *
     * @param  string|array<string, mixed>  $key
     */
    public function checkpoint(string|array $key, mixed $default = null): mixed
    {
        if (is_array($key)) {
            $this->repository->saveCheckpoints($this->name, $key);

            return null;
        }

        return $this->repository->getCheckpoint($this->name, $key, $default);
    }

    /**
     * Set the operation's execution context.
     */
    public function setContext(string $name, OperationRepository $repository): static
    {
        $this->name = $name;
        $this->repository = $repository;

        return $this;
    }
}
