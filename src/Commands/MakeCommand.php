<?php

namespace DirectoryTree\Operations\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

class MakeCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'make:operation {name : The name of the operation}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create a new timestamped deployment operation';

    /**
     * Execute the console command.
     */
    public function handle(Filesystem $files): int
    {
        $name = Str::snake($this->argument('name'));

        if (! preg_match('/^[a-z0-9]+(?:_[a-z0-9]+)*$/', $name)) {
            $this->components->error('Use a descriptive operation name containing letters, numbers, and underscores.');

            return self::FAILURE;
        }

        $directory = rtrim(config('operations.path'), '/');
        $filepath = $directory.'/'.now()->format('Y_m_d_His')."_{$name}.php";

        if ($files->exists($filepath)) {
            $this->components->error('An operation with this filename already exists.');

            return self::FAILURE;
        }

        $files->ensureDirectoryExists($directory);
        $files->put($filepath, $files->get(__DIR__.'/../../stubs/operation.stub'));

        $this->components->info("Operation [{$filepath}] created successfully.");

        return self::SUCCESS;
    }
}
