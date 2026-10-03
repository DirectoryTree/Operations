<?php

namespace DirectoryTree\Operations\Commands;

use DirectoryTree\Operations\Runner;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Illuminate\Contracts\Console\Isolatable;

class RunCommand extends Command implements Isolatable
{
    use ConfirmableTrait;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'operations:run
                            {operation? : The exact operation filename without the .php extension}
                            {--force : Run operations in production without confirmation}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Run pending deployment operations';

    /**
     * Execute the console command.
     */
    public function handle(Runner $runner): int
    {
        if (! $this->confirmToProceed()) {
            return self::FAILURE;
        }

        $count = $runner->run(
            $this,
            before: function (string $name) {
                $this->components->twoColumnDetail($name, '<fg=yellow;options=bold>RUNNING</>');
                $this->newLine();
            },
            after: function (string $name, float $elapsed) {
                $duration = number_format($elapsed, 2);

                $this->newLine();
                $this->components->twoColumnDetail($name, "<fg=gray>{$duration}s</> <fg=green;options=bold>DONE</>");
                $this->newLine();
            },
            operation: $this->argument('operation'),
        );

        $this->components->info($count ? "Completed {$count} operation(s)." : 'No pending operations.');

        return self::SUCCESS;
    }
}
