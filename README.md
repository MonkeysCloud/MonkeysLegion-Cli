# MonkeysLegion CLI

Developer tooling and command-line entrypoint for MonkeysLegion applications. The package bundles the `ml` executable, a reflection-driven `CliKernel`, and a base `Command` class for building rich, colored console commands.

## Requirements

- PHP ^8.4
- MonkeysLegion core packages (installed via Composer dependencies)
- Access to your application's `config/app.php` and `vendor/autoload.php`

## Installation

Install via Composer inside a MonkeysLegion application or skeleton:

```bash
composer require monkeyscloud/monkeyslegion-cli
```

Composer will link the executable at `vendor/bin/ml` (or `bin/ml` in the skeleton). Ensure your project configuration lives at `config/app.php` so the CLI can bootstrap the DI container.

## Running the CLI

Use the `ml` binary to explore and execute commands:

```bash
vendor/bin/ml            # prints all commands grouped by prefix
vendor/bin/ml list       # equivalent to the default
vendor/bin/ml db:        # show only commands under the "db" prefix
vendor/bin/ml migrate    # run a specific command
```

The entrypoint climbs parent directories to locate `vendor/autoload.php`, verifies `config/app.php`, builds the application container, and hands execution to the `CliKernel`.【F:bin/ml†L12-L44】

## How command discovery works

- `CliKernel` accepts an iterable of command classes (typically from `Support\CommandFinder`) and also scans the package's `MonkeysLegion\Cli\Command\*` plus your application's `App\Cli\Command\*` namespace for classes marked with the `#[Command]` attribute.【F:src/CliKernel.php†L14-L128】
- Commands are grouped by prefix (e.g., `db:create`, `make:entity`). Using `list` or a trailing colon (`db:`) prints grouped help with descriptions sourced from the attribute metadata.【F:src/CliKernel.php†L201-L360】
- When a signature is unknown, the kernel suggests similar commands using prefix and Levenshtein matching.【F:src/CliKernel.php†L201-L401】

### CommandFinder

`MonkeysLegion\Cli\Support\CommandFinder::all()` walks Composer's PSR-4 map to include every `Cli/Command/*.php` file across registered namespaces and yields the classes that extend the base `Command` class.【F:src/Support/CommandFinder.php†L10-L64】 Pass its result into the kernel if you need to control discovery yourself.

## Building commands

Create commands by extending `MonkeysLegion\Cli\Console\Command` and annotating the class with `#[Command(signature, description)]`. The base class provides helpers for colored output, prompts, argument parsing, and option handling.【F:src/Console/Attributes/Command.php†L9-L19】【F:src/Console/Command.php†L11-L260】

```php
<?php

namespace App\Cli\Command;

use MonkeysLegion\Cli\Console\Attributes\Command as CommandAttr;
use MonkeysLegion\Cli\Console\Command;

#[CommandAttr('demo:hello', 'Print a greeting')]
final class HelloCommand extends Command
{
    protected function handle(): int
    {
        $name = $this->argument(0) ?? 'world';
        $loud = $this->hasOption('loud');

        $message = $loud ? strtoupper("Hello, {$name}!") : "Hello, {$name}!";
        $this->info($message);

        return self::SUCCESS;
    }
}
```

Key helpers from the base class:

- `info()`, `line()`, and `error()` for colored output to STDOUT/STDERR.【F:src/Console/Command.php†L23-L55】
- `ask()` for interactive prompts with readline fallback.【F:src/Console/Command.php†L57-L68】
- `argument($index)` to fetch positional arguments after the command name.【F:src/Console/Command.php†L70-L96】
- `option($name, $default)`, `hasOption($name)`, and `allOptions()` for long/short flags and `--key=value` syntax.【F:src/Console/Command.php†L98-L253】

Register the command under `App\Cli\Command` and it will be auto-discovered by the kernel when the application boots.

## Built-in commands

The package ships with a suite of commands covering database setup, migrations, schema updates, seeding, routing diagnostics, cache, OpenAPI export, and code generation. Examples include:

- Database lifecycle: `db:create`, `migrate`, `rollback`, `schema:update`, `db:seed`
- Cache and keys: `cache:clear`, `key:generate`
- Routing and HTTP: `route:list`, `route:cache`
- Scaffolding: `make:controller`, `make:entity`, `make:middleware`, `make:policy`, `make:seeder`
- Developer utilities: `tinker`, `openapi:export`

Run `vendor/bin/ml` or `vendor/bin/ml list` to see the full, attribute-driven catalog available in your installation.【F:src/CliKernel.php†L245-L360】

## Programmatic execution with MLRunner

Use `MonkeysLegion\Cli\Application\MLRunner` when you need to run CLI commands from application code (controllers, jobs, tests, internal tools).

```php
<?php

use MonkeysLegion\Cli\Application\MLRunner;
use MonkeysLegion\Cli\CliKernel;
use MonkeysLegion\Cli\Support\CommandFinder;

// Bootstrap once (example)
$kernel = new CliKernel(CommandFinder::all());
MLRunner::boot($kernel);

// 1) Run and get exit code
$code = MLRunner::call('migrate');

// 2) Capture command output
$output = MLRunner::capture('route:list');

// 3) Get structured result
$result = MLRunner::inspect('db:seed');
// ['exit_code' => 0, 'output' => '...', 'success' => true]

// 4) Execute raw argv-style input
$code = MLRunner::raw(['ml', 'make:entity', 'User']);

// 5) Run silently (discard output)
$code = MLRunner::silent('cache:clear');

// 6) Process-style terminate with exit code
// MLRunner::terminate('list');
```

### MLRunner API quick reference

- `MLRunner::boot(CliKernel $kernel): void`  
  Registers the kernel instance used by all static runner calls.
  Repeated calls are ignored (idempotent boot behavior).
- `MLRunner::call(string $command): int`  
  Executes a command string, strips optional `ml` prefix, and returns exit code.
- `MLRunner::capture(string $command): string`  
  Executes and returns buffered output.
- `MLRunner::inspect(string $command): array{exit_code:int,output:string,success:bool}`  
  Executes and returns structured diagnostics.
- `MLRunner::raw(array $args): int`  
  Executes argv-style input (`['ml', 'command', ...]`).
- `MLRunner::silent(string $command): int`  
  Executes while suppressing all output.
- `MLRunner::terminate(string $command): never`  
  Executes and exits current PHP process using command exit code.

> Note: `MLRunner::boot()` must be called before running commands.  
> `CliKernel` command discovery boots once per process/request lifecycle, and nested `run()` calls are blocked to prevent infinite recursion.

## Troubleshooting

If a command fails to load or execute, the kernel prints colored warnings and errors. Setting `APP_DEBUG=true` will include stack traces for runtime failures.【F:src/CliKernel.php†L124-L240】 Missing configuration files (e.g., `config/app.php`) will cause the entrypoint to exit with a descriptive error before bootstrapping.【F:bin/ml†L12-L36】
