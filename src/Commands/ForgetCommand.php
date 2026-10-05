<?php

namespace DirectoryTree\Operations\Commands;

use DirectoryTree\Operations\OperationRepository;
use Illuminate\Console\Command;

class ForgetCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'operations:forget
                            {name : The operation filename without the .php extension}
                            {--force : Forget the operation without confirmation}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Delete the completion record and checkpoints of an operation';

    /**
     * Execute the console command.
     */
    public function handle(OperationRepository $repository): int
    {
        $name = $this->argument('name');

        $this->components->warn('Forgetting does not undo previous effects. Running the operation again may duplicate them.');

        if (! $this->option('force') && ! $this->confirm("Forget operation [{$name}]?")) {
            $this->components->warn('Command cancelled.');

            return self::FAILURE;
        }

        if (! $repository->forget($name)) {
            $this->components->error("No completion record or checkpoints found for operation [{$name}].");

            return self::FAILURE;
        }

        $this->components->info("Operation [{$name}] forgotten. If its file is present, it will run on the next operations:run.");

        return self::SUCCESS;
    }
}
