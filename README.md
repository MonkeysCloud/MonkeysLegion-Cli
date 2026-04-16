# MonkeysLegion CLI v2

> Attribute-driven, PHP 8.4+ command-line toolkit for the MonkeysLegion framework.

[![PHP Version](https://img.shields.io/badge/php-%3E%3D8.4-8892BF.svg)](https://php.net)
[![License](https://img.shields.io/badge/license-MIT-brightgreen.svg)](LICENSE)
[![Tests](https://img.shields.io/badge/tests-152%20passing-success)](phpunit.xml.dist)

---

## Table of Contents

- [Installation](#installation)
- [Quick Start](#quick-start)
- [Architecture](#architecture)
- [Command Reference](#command-reference)
- [Entity Generator](#entity-generator)
- [Migration System](#migration-system)
- [Maker Commands](#maker-commands)
- [Writing Custom Commands](#writing-custom-commands)
- [Console Output API](#console-output-api)
- [Testing](#testing)
- [Contributing](#contributing)

---

## Installation

```bash
composer require monkeyscloud/monkeyslegion-cli:^2.0
```

**Requirements:** PHP 8.4+, `nikic/php-parser` (for entity AST manipulation).

---

## Quick Start

```bash
# List all commands
php ml list

# Filter by prefix
php ml make:

# Generate an entity interactively
php ml make:entity User

# Generate an entity non-interactively (CI mode)
php ml make:entity Product --fields="name:string,price:decimal:nullable,sku:string"

# Run migrations
php ml migrate
```

---

## Architecture

### v2 Design Principles

1. **Attribute-driven** — Commands register via `#[Command('name', 'description')]` attributes
2. **PHP 8.4+** — Leverages asymmetric visibility (`public private(set)`), property hooks, `readonly` classes
3. **Zero boilerplate** — Entity properties are public (no getter/setter generation); only collection relations get add/remove/get helpers
4. **Dual mode** — Every generator supports both interactive wizard and `--flags` non-interactive usage
5. **PSR-4 split** — Each enum/class lives in its own file for proper autoloading

### Directory Structure

```
src/
├── CliKernel.php              # Attribute-driven kernel with auto-discovery
├── Command/                   # 39 built-in commands
│   ├── MakerHelpers.php       # Shared trait for make:* commands
│   ├── MakeEntityCommand.php  # Interactive entity wizard
│   ├── MigrateCommand.php     # Migration runner
│   └── ...
├── Config/                    # Configuration value objects
│   ├── EntityConfig.php       # Aggregate config for entity wizard
│   ├── FieldType.php          # Database field type enum (36 types)
│   ├── FieldTypeConfig.php    # Field type resolver
│   ├── PhpTypeMap.php         # DB type → PHP type mapping
│   ├── RelationKind.php       # Relation kind enum (4 kinds)
│   ├── RelationKeywordMap.php # Relation attribute names
│   └── RelationInverseMap.php # Inverse relation resolver
├── Console/                   # Console abstractions
│   ├── Attributes/Command.php # #[Command] attribute class
│   ├── Command.php            # Base command with 20+ helpers
│   ├── Output/
│   │   ├── TableRenderer.php  # UTF-8 box-drawing tables
│   │   ├── ProgressBar.php    # Progress bar with ETA
│   │   └── Spinner.php        # Braille animation spinner
│   └── Traits/Cli.php         # Colored output builder (CliLineBuilder)
├── Helpers/
│   └── Identifier.php         # PHP identifier validator
├── Service/
│   └── ClassManipulator.php   # PhpParser-based AST entity editor
└── Support/
    └── CommandFinder.php      # Auto-discovery via Composer PSR-4
```

---

## Command Reference

### General

| Command | Description |
|---------|-------------|
| `list` / `help` | Display all available commands |
| `about` | Framework, PHP, and environment information |
| `down` | Put application in maintenance mode |
| `up` | Bring application out of maintenance mode |
| `tinker` | Interactive REPL with DI container |
| `optimize` | Run config:cache + route:cache + clear old caches |

### Database (`db:`)

| Command | Description |
|---------|-------------|
| `db:create` | Create the database schema from .env credentials |
| `db:seed` | Run database seeders (optionally specify one) |
| `db:wipe` | Drop all tables in the database |

### Cache (`cache:` / `config:`)

| Command | Description |
|---------|-------------|
| `cache:clear` | Clear the compiled view cache |
| `config:cache` | Compile config files into a cached file |

### Environment (`env:`)

| Command | Description |
|---------|-------------|
| `env:sync` | Compare .env with .env.example for missing keys |
| `key:generate` | Generate a new APP_KEY in your .env file |

### Migration (`migrate:`)

| Command | Aliases | Description |
|---------|---------|-------------|
| `migrate` | `m` | Run pending database migrations |
| `migrate:rollback` | `m:rb` | Rollback the last migration batch |
| `migrate:fresh` | — | Drop all tables and re-run all migrations |
| `migrate:refresh` | — | Rollback all and re-run all migrations |
| `migrate:status` | `m:st` | Show ran and pending migrations |
| `make:migration` | — | Generate a blank migration file |
| `schema:update` | — | Compare entities → database and apply changes |

### Maker (`make:`)

| Command | Description |
|---------|-------------|
| `make:entity` | Generate or update an Entity class with fields & relationships |
| `make:controller` | Generate a new Controller class |
| `make:middleware` | Generate a PSR-15 middleware class |
| `make:service` | Generate a service class |
| `make:dto` | Generate a readonly DTO class |
| `make:enum` | Generate a backed enum class |
| `make:event` | Generate a domain event class |
| `make:listener` | Generate an event listener class |
| `make:job` | Generate a queue job class |
| `make:resource` | Generate a JSON:API resource class |
| `make:factory` | Generate an entity factory for testing |
| `make:observer` | Generate an entity lifecycle observer |
| `make:policy` | Generate an authorization policy class |
| `make:seeder` | Generate a database seeder class |
| `make:test` | Generate a PHPUnit test class |
| `make:command` | Generate a custom CLI command |

### Routing (`route:`)

| Command | Description |
|---------|-------------|
| `route:list` | List all registered routes (filter with `--method`, `--path`) |
| `route:cache` | Cache all routes for production |

### API

| Command | Description |
|---------|-------------|
| `openapi:export` | Dump OpenAPI spec to stdout or a file |

---

## Entity Generator

### Interactive Mode

```bash
php ml make:entity User
```

Launches a wizard that lets you:
1. **Add fields** — Choose from 36 database types with nullable support
2. **Add relationships** — OneToOne, OneToMany, ManyToOne, ManyToMany with automatic inverse generation
3. **Save** — Writes the entity using PhpParser AST manipulation (non-destructive)

### Non-Interactive Mode

```bash
php ml make:entity Product --fields="name:string,price:decimal:nullable,sku:string,active:boolean"
```

Parses `field:type[:nullable]` triplets and applies them in a single pass. Ideal for CI pipelines.

### Generated Entity (v2 Style)

```php
<?php
declare(strict_types=1);

namespace App\Entity;

use MonkeysLegion\Entity\Attributes\Entity;
use MonkeysLegion\Entity\Attributes\Field;
use MonkeysLegion\Entity\Attributes\Id;

#[Entity]
class Product
{
    #[Id]
    #[Field(type: 'unsignedBigInt', autoIncrement: true)]
    public private(set) int $id;

    #[Field(type: 'string')]
    public string $name;

    #[Field(type: 'decimal', nullable: true)]
    public ?float $price = null;

    #[Field(type: 'string')]
    public string $sku;

    #[Field(type: 'boolean')]
    public bool $active;
}
```

**Key v2 differences from v1:**
- `#[Id]` + `#[Field]` dual attributes (instead of combined)
- `public private(set)` for the primary key (PHP 8.4 asymmetric visibility)
- No getter/setter methods — public properties are the API
- Collection relations still get `add{Entity}()`, `remove{Entity}()`, `get{Prop}()` helpers

---

## Migration System

Built on top of `monkeyscloud/monkeyslegion-migration` v1.x:

```bash
# Generate a blank migration
php ml make:migration CreateUsersTable

# Run pending migrations
php ml migrate

# Check status
php ml migrate:status

# Rollback last batch
php ml migrate:rollback

# Fresh start (drop all + re-run)
php ml migrate:fresh

# Refresh (rollback all + re-run)
php ml migrate:refresh

# Auto-generate from entities
php ml schema:update
php ml schema:update --force   # Apply without confirmation
```

---

## Maker Commands

All maker commands follow a consistent pattern:

```bash
# With argument
php ml make:service UserService

# Interactive (no argument)
php ml make:service
# → prompts for name

# Force overwrite
php ml make:service UserService --force
```

Generated stubs use v2 conventions:
- `declare(strict_types=1)`
- `final class` by default
- PSR-4 namespace matching directory structure
- Typed properties and constructor promotion

---

## Writing Custom Commands

### 1. Create the Command

```php
<?php
declare(strict_types=1);

namespace App\Command;

use MonkeysLegion\Cli\Console\Attributes\Command as CommandAttr;
use MonkeysLegion\Cli\Console\Command;

#[CommandAttr('app:sync', 'Sync data from external API', aliases: ['sync'])]
final class SyncCommand extends Command
{
    public function __construct(
        private readonly ApiClient $client,
    ) {
        parent::__construct();
    }

    protected function handle(): int
    {
        $count = $this->option('limit', 100);
        $force = $this->hasOption('force');

        $this->info("Syncing {$count} records...");

        $result = $this->spinner('Fetching data...', function () use ($count) {
            return $this->client->fetch((int) $count);
        });

        $this->table(
            ['ID', 'Name', 'Status'],
            array_map(fn($r) => [$r->id, $r->name, $r->status], $result),
        );

        $this->info("✅ Synced " . count($result) . " records.");

        return self::SUCCESS;
    }
}
```

### 2. Auto-Discovery

Commands in `app/Command/` or `app/Cli/Command/` are auto-discovered. No registration needed.

### 3. Available Helpers

```php
// Output
$this->info('Green message');
$this->warn('Yellow warning');
$this->error('Red error → STDERR');
$this->comment('Gray italic');
$this->alert('Boxed alert');
$this->line('Plain text');
$this->newLine(2);

// Colored output builder
$this->cliLine()
    ->add('Status: ', 'cyan', 'bold')
    ->add('OK', 'green')
    ->print();

// Interactive prompts
$name    = $this->ask('Name:');
$confirm = $this->confirm('Continue?', true);
$choice  = $this->choice('Option:', ['A', 'B', 'C'], 0);
$pass    = $this->secret('Password:');
$val     = $this->anticipate('Search:', $suggestions, 'default');

// Progress
$this->progressStart(100, 'Processing');
for ($i = 0; $i < 100; $i++) {
    $this->progressAdvance();
}
$this->progressFinish();

// Spinner
$result = $this->spinner('Computing...', fn() => heavyTask());

// Table
$this->table(['Name', 'Age'], [['Alice', '30'], ['Bob', '25']]);

// Arguments & options
$arg    = $this->argument(0);           // positional
$opt    = $this->option('name', 'def'); // --name=value
$flag   = $this->hasOption('verbose');  // --verbose
$all    = $this->allOptions();          // all --options
```

---

## Console Output API

### CliLineBuilder

Build multi-colored, multi-styled output lines:

```php
$this->cliLine()
    ->add('Error: ', 'red', 'bold')
    ->add('File not found ', 'white')
    ->add('/path/to/file', 'yellow', 'underline')
    ->print();

// Semantic shortcuts
$this->cliLine()
    ->success('✓ ')
    ->info('Processing ')
    ->muted('(3 items)')
    ->print();
```

**Colors:** `black`, `red`, `green`, `yellow`, `blue`, `magenta`, `cyan`, `white`, `gray`, `bright_*`
**Styles:** `bold`, `dim`, `italic`, `underline`, `blink`, `reverse`, `hidden`, `strikethrough`

### TableRenderer

UTF-8 box-drawing tables with column alignment:

```php
$this->table(
    ['Name', 'Type', 'Nullable'],
    [
        ['id', 'unsignedBigInt', 'No'],
        ['email', 'string', 'No'],
        ['bio', 'text', 'Yes'],
    ],
    ['l', 'l', 'c'],  // left, left, center alignment
);
```

### ProgressBar & Spinner

```php
// Progress bar with ETA
$this->progressStart(1000, 'Migrating');
// → [████████████░░░░░░░░░░░░░░░░░░] 40% 400/1000 ETA 0:02

// Spinner for indeterminate operations
$result = $this->spinner('Analyzing schema...', fn() => $scanner->scan());
// → ⠙ Analyzing schema...
// → ✓ Analyzing schema
```

---

## Testing

```bash
# Run all tests
vendor/bin/phpunit

# With coverage (requires pcov/xdebug)
vendor/bin/phpunit --coverage-text

# Specific test suite
vendor/bin/phpunit tests/Unit/Service/ClassManipulatorTest.php
```

**Current stats:** 152 tests, 299 assertions across 12 test files.

### Test Coverage

| Component | Tests | Focus |
|-----------|-------|-------|
| `ClassManipulatorTest` | 18 | AST manipulation, field/relation generation |
| `CommandTest` | 30 | argument(), option(), hasOption(), output, safeQuery |
| `EntityConfigTest` | 12 | PhpTypeMap, RelationKeywordMap, RelationInverseMap |
| `FieldTypeConfigTest` | 7 | Field type parsing and validation |
| `CliLineBuilderTest` | 15 | Multi-colored output building |
| `CommandAttributeTest` | 8 | #[Command] attribute properties |
| `ProgressBarTest` | 7 | Progress rendering |
| `TableRendererTest` | 20 | Table formatting and alignment |
| `SpinnerTest` | 8 | Spinner animation |
| `MakerHelpersTest` | 10 | toPascalCase, ensureSuffix, etc. |
| `IdentifierTest` | 9 | PHP identifier validation |
| Additional | 8 | Edge cases and integration |

---

## Contributing

1. Fork and create a feature branch from `2.x-dev`
2. Follow MonkeysLegion v2 coding standards (PSR-12 + `declare(strict_types=1)`)
3. Write tests for new functionality
4. Run `vendor/bin/phpunit` and ensure all tests pass
5. Submit a PR against `2.x-dev`

---

## License

MIT © 2026 MonkeysCloud Team
