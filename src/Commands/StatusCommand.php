<?php

namespace DirectoryTree\Operations\Commands;

use DirectoryTree\Operations\OperationRepository;
use DirectoryTree\Operations\Runner;
use Illuminate\Console\Command;

class StatusCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'operations:status';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Show pending and completed deployment operations';

    /**
     * Execute the console command.
     */
    public function handle(Runner $runner, OperationRepository $repository): int
    {
        $files = $runner->files();
        $completed = $repository->completed();
        $names = $completed->keys()->merge(array_keys($files))->unique()->sort()->values();

        if ($names->isEmpty()) {
            $this->components->info('No operations found.');

            return self::SUCCESS;
        }

        $this->table(['Operation', 'Status', 'Completed at', 'File'], $names->map(fn (string $name) => [
            $name,
            $completed->has($name) ? 'Completed' : 'Pending',
            $completed->get($name) ?? '—',
            isset($files[$name]) ? 'Present' : 'Missing',
        ])->all());

        return self::SUCCESS;
    }
}
