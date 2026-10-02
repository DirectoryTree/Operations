# Operations

Run one-time deployment tasks in Laravel and keep a record of what completed.

Create a timestamped operation, put your code in `handle()`, and add `operations:run` to your deployment script. Operations run synchronously in filename order. Once an operation succeeds, its name is recorded in the `operations` table and subsequent runs skip it.

## Installation

Requires PHP 8.2+ and Laravel 12 or 13. Laravel 13 requires PHP 8.3+.

The package is under development and has no tagged release yet. To try the current `master` branch, add the GitHub repository to your application's Composer configuration:

```bash
composer config repositories.operations vcs https://github.com/DirectoryTree/Operations
composer require directorytree/operations:dev-master

php artisan vendor:publish --tag=operations-migrations
php artisan migrate
```

The service provider is discovered automatically. Laravel updates the published migration's timestamp when `database.migrations.update_date_on_publish` is enabled. Publish this migration once during setup and commit it with your application.

## Creating operations

```bash
php artisan make:operation backfill_company_billing_verifications
```

This creates a file such as `operations/2026_10_02_120000_backfill_company_billing_verifications.php`:

```php
<?php

use App\Models\Company;
use DirectoryTree\Operations\Operation;
use Illuminate\Console\Command;

return new class extends Operation
{
    public function handle(Command $command): void
    {
        Company::query()
            ->whereNull('billing_email_verified_at')
            ->update(['billing_email_verified_at' => now()]);
    }
};
```

Use operations for one-time data changes, backfills, and deployment tasks. Keep repeatable application setup in your seeders.

## Running operations

```bash
php artisan operations:run
php artisan operations:status
```

Add the runner after your schema migrations in the appropriate deployment stage:

```bash
set -e

php artisan migrate --force --isolated=1
php artisan operations:run --force --isolated=1
```

`--force` skips Laravel's production confirmation. It does not rerun completed operations or enable isolation.

The runner stops when an operation throws an exception. The command fails, the operation stays pending, and later operations are not executed. Running the command again retries unfinished work and skips anything already completed.

Filename order determines execution order among the operations currently pending. Adding a file with an earlier timestamp does not undo or reorder work that already completed.

The filename without `.php` is the operation's identity. Keep completed filenames unchanged and create another operation when you need a correction. There is no rollback or rerun command.

`operations:status` shows pending and completed operations, including completed entries whose files have been removed. Neither status checks nor subsequent runs load completed operation files. Only remove a file after every database that needs it has completed it; a fresh database cannot execute a deleted operation.

## Console output

Every operation receives the running Artisan command as a required `Command $command` argument to `handle()`. Use it to print messages, render tables, and display progress:

```php
use Illuminate\Console\Command;

public function handle(Command $command): void
{
    $command->info('Backfilling company names...');

    $output = $command->getOutput();
    $output->progressStart();

    Company::query()->whereNull('display_name')->chunkById(500, function ($companies) use ($output) {
        foreach ($companies as $company) {
            $company->update(['display_name' => $company->name]);
        }

        $output->progressAdvance($companies->count());
    });

    $output->progressFinish();

    $command->info('Company names updated.');
}
```

The runner prints a `RUNNING` line before each operation and a `DONE` line with elapsed time after recording completion. Your operation's output appears between them. Finish any progress bars you create before returning from `handle()`.

The command's normal verbosity options apply, including `--quiet` and `--verbose`. Operations should run unattended during deployment, so avoid requiring interactive answers.

The runner passes the active command directly to `handle()`. When invoking an operation or `Runner::run()` yourself, pass an initialized command as an argument. There is no command property or separate setup method on the operation.

## Retrying safely

Write operations so they can safely run again after a partial failure. For example, update rows that still need changing instead of incrementing every row unconditionally.

An operation can finish its work and then lose its database connection before recording completion. The next run will attempt it again. The ledger prevents repeating recorded successes; it cannot guarantee exactly-once execution of arbitrary side effects.

For database work, implement the `WithinTransaction` marker interface to opt into a transaction:

```php
use DirectoryTree\Operations\Contracts\WithinTransaction;
use DirectoryTree\Operations\Operation;
use Illuminate\Console\Command;

return new class extends Operation implements WithinTransaction
{
    public function handle(Command $command): void
    {
        // Your database changes...
    }
};
```

The transaction includes the operation and its completion record on the configured operations connection. Writes on other connections, external API calls, and database statements that implicitly commit are outside that guarantee. Transactions are disabled by default so a large backfill does not accidentally hold one transaction open for its entire run.

## Dispatching jobs

Operations always execute in the command's process. They can dispatch ordinary Laravel jobs:

```php
use Illuminate\Console\Command;

public function handle(Command $command): void
{
    RebuildSearchIndex::dispatch();
}
```

Here, completing the operation means the dispatch finished. It does not mean the search index has been rebuilt. Laravel's queue owns job execution and retries. Keep dependent deployment steps separate until that background work completes, and make dispatching operations safe to retry after partial dispatch.

If you enable a transaction, use Laravel's after-commit dispatch behavior for jobs that must see committed changes. Database completion and publishing to an external queue are not one atomic transaction.

## Configuration

```bash
php artisan vendor:publish --tag=operations-config
```

```php
return [
    'path' => base_path('operations'),

    'connection' => null,
];
```

`connection` selects the database connection for the ledger, its migration, and optional transactions. `null` uses the application's default connection. Configure it before migrating and keep it stable across releases. It does not change the connection used by your application models.

### Overlapping deployments

Like Laravel migrations, `operations:run` supports Laravel's [isolatable commands](https://laravel.com/docs/13.x/artisan#isolatable-commands). Isolation is opt-in:

```bash
php artisan operations:run --force --isolated
```

Laravel acquires a lock through the application's default cache store before running the command and releases it when the command finishes or throws. If another isolated invocation holds the lock, the command skips execution and exits successfully. Use `--isolated=1` when your deployment must fail instead of proceeding without running the operations:

```bash
php artisan operations:run --force --isolated=1
```

Every deployment invocation must opt into isolation. A command without `--isolated`, or a direct call to `Runner::run()`, does not acquire or respect the command lock.

All deployment hosts targeting the same ledger must share the default cache store and cache prefix. Use a shared Redis or database cache store in production. An array store or a local file cache does not coordinate separate hosts. Independent applications sharing a cache should use different cache prefixes.

Laravel's default command lock expires after one hour and is not automatically renewed. Keep the deployment process's maximum runtime below that expiry; longer runs need a customized command with Laravel's `isolationLockExpiresAt()` hook. If a run outlives its lock, another invocation can start and repeat side effects. Do not clear the shared cache while operations are running.

Migration and operation commands use separate locks. Isolation does not coordinate the entire deployment or wait for another deployment's migrations to finish.

## Adopting operations in an existing application

Start with new deployment tasks. Moving historical seeders into `operations` makes them pending on every database without a matching completion record. Review existing tasks individually before migrating them.

Run operations from the deployment runtime that owns the work, after its schema and required dependencies are available. For long backfills, keep the application compatible with both old and new data until the work finishes.

## Development

```bash
composer install
composer test
composer lint
```

The test suite uses Pest, Orchestra Testbench, and SQLite. It does not need your application's database.

## License

Operations is open-source software licensed under the [MIT license](LICENSE.md).
