<h1 align="center">Operations</h1>

<p align="center">Run one-time deployment operations in Laravel.</p>

<p align="center">
<a href="https://github.com/DirectoryTree/Operations/actions"><img src="https://img.shields.io/github/actions/workflow/status/DirectoryTree/Operations/tests.yml?branch=master&style=flat-square" alt="Tests"></a>
<a href="https://packagist.org/packages/directorytree/operations"><img src="https://img.shields.io/packagist/dt/directorytree/operations.svg?style=flat-square" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/directorytree/operations"><img src="https://img.shields.io/packagist/v/directorytree/operations.svg?style=flat-square" alt="Latest Version"></a>
<a href="https://github.com/DirectoryTree/Operations/blob/master/LICENSE.md"><img src="https://img.shields.io/github/license/DirectoryTree/Operations?style=flat-square" alt="License"></a>
</p>

<p align="center">
  <a href="#installation">Installation</a>
  <span> · </span>
  <a href="#usage">Usage</a>
  <span> · </span>
  <a href="#deployment">Deployment</a>
  <span> · </span>
  <a href="#configuration">Configuration</a>
</p>

---

Operations gives your one-time data changes, backfills, and deployment tasks a place alongside your migrations.

Create a timestamped file in your application's `operations` directory, write your code in `handle()`, and run it during deployment. Completed operations are recorded in the `operations` table and skipped on subsequent runs.

```bash
php artisan make:operation backfill_company_names

php artisan operations:run
```

## Requirements

- PHP 8.2 or higher (PHP 8.3 or higher for Laravel 13)
- Laravel 12 or 13

## Installation

Install the package with Composer:

```bash
composer require directorytree/operations
```

Publish and run the migrations:

```bash
php artisan vendor:publish --tag=operations-migrations
php artisan migrate
```

The service provider is registered automatically.

## Usage

### Creating Operations

```bash
php artisan make:operation backfill_company_names
```

This creates a file such as `operations/2026_10_02_120000_backfill_company_names.php`:

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
            ->whereNull('display_name')
            ->eachById(function (Company $company) {
                $company->update(['display_name' => $company->name]);
            });
    }
};
```

### Running Operations

Run all pending operations in filename order:

```bash
php artisan operations:run
```

The runner stops when an operation throws an exception. The command fails, the operation stays pending, and later operations are not executed. Running the command again retries unfinished work and skips anything already completed.

The filename without `.php` is the operation's identity. Keep completed filenames unchanged and create another operation when you need a correction. Operations do not support rollbacks.

To run a single pending operation, pass its exact filename without `.php`:

```bash
php artisan operations:run 2026_10_03_120000_backfill_company_names
```

The name must contain only letters, numbers, underscores, and hyphens. Paths, the `.php` extension, and partial names are not accepted. Invalid or unknown names fail without running any operations.

Only the selected operation runs. It uses the same transaction support and completion ledger as a full run, and is skipped if already completed. Its file must still exist, even if it has a completion record.

### Testing Operations

Call the command from a Laravel/Pest feature test to exercise a specific operation:

```php
use App\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\artisan;

uses(RefreshDatabase::class);

test('company names are backfilled', function () {
    $company = Company::factory()->create([
        'name' => 'Acme',
        'display_name' => null,
    ]);

    artisan('operations:run', [
        'operation' => '2026_10_03_120000_backfill_company_names',
    ])->assertSuccessful();

    expect($company->fresh()->display_name)->toBe('Acme');
});
```

Use your application's Laravel test case and a dedicated test database with the published operations migration. `RefreshDatabase` resets the completion ledger between tests; invoking the same operation twice within a test skips it on the second call. If you configure a separate `operations.connection`, point it to a test database and include it in your database reset setup.

To test whether an operation can safely resume after partial work, create that partial state without a completion record, run the operation, and assert the expected result. Running a completed operation again only verifies that it is skipped.

### Checking Status

```bash
php artisan operations:status
```

View pending and completed operations, including completion timestamps and whether each file is still present. Status checks do not load operation files.

### Console Output

Every operation receives the running Artisan command as a required `Command $command` argument to `handle()`. Use it to print messages, render tables, and display progress:

```php
use App\Models\Company;
use Illuminate\Console\Command;

public function handle(Command $command): void
{
    $command->info('Backfilling company names...');

    $companies = Company::query()->whereNull('display_name');

    $output = $command->getOutput();
    $output->progressStart($companies->count());

    $companies->chunkById(500, function ($companies) use ($output) {
        foreach ($companies as $company) {
            $company->update(['display_name' => $company->name]);
        }

        $output->progressAdvance($companies->count());
    });

    $output->progressFinish();

    $command->info('Company names updated.');
}
```

Running this example against 1,500 companies produces the following output:

```text
$ php artisan operations:run

  2026_10_02_120000_backfill_company_names ............................................... RUNNING

Backfilling company names...
    0/1500 [░░░░░░░░░░░░░░░░░░░░░░░░░░░░]   0%
 1000/1500 [▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓░░░░░░░░░░]  66%
 1500/1500 [▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓] 100%

Company names updated.

  2026_10_02_120000_backfill_company_names ............................................ 0.10s DONE

   INFO  Completed 1 operation(s).
```

In an interactive terminal, the progress bar updates in place. The capture above shows its successive updates; timings and intermediate counts vary with the work being performed.

The runner prints a `RUNNING` line before each operation and a `DONE` line with elapsed time after recording completion. Your operation's output appears between them. Finish any progress bars you create before returning from `handle()`.

The command's normal verbosity options apply, including `--quiet` and `--verbose`.

### Retrying Operations

Write operations so they can safely run again after a partial failure. For example, update rows that still need changing instead of incrementing every row unconditionally.

An operation can finish its work and then lose its database connection before recording completion. The next run will attempt it again. The ledger prevents repeating recorded successes; it cannot guarantee exactly-once execution of arbitrary side effects.

### Forgetting Operations

To deliberately run a completed operation again, forget its completion record using the full filename without `.php`:

```bash
php artisan operations:forget 2026_10_02_120000_backfill_company_names
```

The command asks for confirmation. Use `--force` to skip the prompt:

```bash
php artisan operations:forget 2026_10_02_120000_backfill_company_names --force
```

Forgetting deletes the completion record and any saved checkpoints. It does not undo previous effects, delete the file, or execute the operation. If the file is present, the next `operations:run` will execute it again from the beginning. Make sure it is safe to repeat.

You can also forget records whose files have been removed, or clear checkpoints from an unfinished operation. The command fails if neither a completion record nor checkpoints match the supplied name. Failed operations are already pending; only forget them if you want to discard their saved progress.

### Checkpoints

Use `checkpoint()` inside an operation to save progress between attempts. Pass a key to read a value, an optional default for a missing key, or an array to persist values:

```php
$cursor = $this->checkpoint('cursor');
$lastId = $this->checkpoint('last_id', 0);

$this->checkpoint(['last_id' => 15000]);

$this->checkpoint([
    'cursor' => $nextCursor,
    'processed' => $processed,
]);
```

Keys are scoped to the operation's filename. Writes update the supplied keys and preserve other saved values. Values are stored as JSON and read back as scalars or arrays; use JSON-compatible values such as strings, numbers, booleans, arrays, and `null`. Saving `null` retains the key, so reading it returns `null` even when a default is provided.

For example, save the last processed ID to resume a backfill:

```php
use App\Models\Company;
use DirectoryTree\Operations\Operation;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

return new class extends Operation
{
    public function handle(Command $command): void
    {
        Company::query()
            ->where('id', '>', $this->checkpoint('last_id', 0))
            ->chunkById(1000, function (Collection $companies) {
                foreach ($companies as $company) {
                    $company->update(['display_name' => $company->name]);
                }

                $this->checkpoint(['last_id' => $companies->last()->id]);
            });
    }
};
```

After a failure, `operations:run` starts `handle()` again. Your code reads the saved checkpoint to decide where to continue. Saving a checkpoint does not mark the operation complete or prevent concurrent execution. Checkpoints remain after completion until the operation is forgotten.

In the example above, an interrupted chunk may run again. Write work that can safely repeat, or wrap each chunk's database changes and checkpoint write in a transaction on the same connection. Checkpoints use `operations.connection` and participate in its active transaction. With `WithinTransaction`, a failure rolls back all checkpoints written during that attempt along with the operation's database changes. External effects, such as API calls, are not rolled back.

Existing installations must publish and run the new checkpoint migration before using checkpoints or `operations:forget`:

```bash
php artisan vendor:publish --tag=operations-checkpoints-migration
php artisan migrate
```

### Transactions

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

### Dispatching Jobs

Operations always execute in the command's process. They can dispatch ordinary Laravel jobs:

```php
use Illuminate\Console\Command;

public function handle(Command $command): void
{
    RebuildSearchIndex::dispatch();
}
```

The operation is marked complete after dispatching the job. Laravel's queue handles the job's execution and retries; the operation does not wait for it to finish.

If you enable a transaction, use Laravel's after-commit dispatch behavior for jobs that must see committed changes. Database completion and publishing to an external queue are not one atomic transaction.

### Pruning Operations

You can delete old operation files after every database that needs them has completed them. Their completion records remain in the `operations` table, and `operations:status` shows the files as missing.

Completed files are never loaded by the runner, so they can remain in the repository even when they reference application code that has since changed.

Pruning is a manual step. A fresh database cannot execute a deleted operation, so keep any setup it still needs in your migrations or seeders.

## Deployment

Run operations after your migrations:

```bash
set -e

php artisan migrate --force --isolated=1
php artisan operations:run --force --isolated=1
```

`--force` skips Laravel's production confirmation. It does not rerun completed operations or enable isolation.

### Isolating Operations

Like Laravel migrations, `operations:run` supports Laravel's [isolatable commands](https://laravel.com/docs/13.x/artisan#isolatable-commands). Isolation is opt-in:

```bash
php artisan operations:run --force --isolated
```

Laravel acquires a lock through the application's default cache store before running the command and releases it when the command finishes or throws. If another isolated invocation holds the lock, the command skips execution and exits successfully. Use `--isolated=1` when your deployment must fail instead of proceeding without running the operations:

```bash
php artisan operations:run --force --isolated=1
```

Every invocation must use `--isolated` to participate in locking.

All servers must share the same default cache store and cache prefix for isolation to work across servers.

Laravel's default isolation lock expires after one hour and is not renewed automatically. Operations that run longer may overlap with another invocation.

Migration and operation commands use separate locks; isolation applies only to the command being run.

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

`connection` selects the database connection for the operations and checkpoint tables, and optional transactions. `null` uses the application's default connection. It does not change the connection used by your application models.
