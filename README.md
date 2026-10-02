<h1 align="center">Operations</h1>

<p align="center">Run one-time deployment operations in Laravel.</p>

<p align="center">
<a href="https://github.com/DirectoryTree/Operations/actions"><img src="https://img.shields.io/github/actions/workflow/status/DirectoryTree/Operations/tests.yml?branch=master&style=flat-square" alt="Tests"></a>
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

Publish and run the migration:

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

The filename without `.php` is the operation's identity. Keep completed filenames unchanged and create another operation when you need a correction. There is no rollback or rerun command.

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

`connection` selects the database connection for the operations table and optional transactions. `null` uses the application's default connection. It does not change the connection used by your application models.
