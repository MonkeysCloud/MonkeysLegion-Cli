<?php
declare(strict_types=1);

namespace MonkeysLegion\Cli;

use MonkeysLegion\Cli\Console\Attributes\Command as CommandAttr;
use MonkeysLegion\Cli\Console\Command;
use MonkeysLegion\Cli\Console\Traits\Cli;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use ReflectionException;

/**
 * MonkeysLegion Framework — CLI Package
 *
 * Attribute-driven CLI kernel with prefix-based grouping,
 * command aliases, and hidden command filtering.
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
final class CliKernel
{
    use Cli;

    /** @var array<string, class-string<Command>> signature => class */
    private array $map = [];

    /** @var array<string, string> alias => canonical signature */
    private array $aliases = [];

    /** @var array<string, bool> hidden signatures */
    private array $hidden = [];

    /** @var array<string, string> signature => description cache */
    private array $descriptions = [];

    /** @var array<string, array<string, class-string<Command>>> prefix => [name => class] */
    private array $groupedCommands = [];

    /** @var list<string> Errors encountered during command loading */
    private array $loadingErrors = [];

    /**
     * @param ContainerInterface                  $container Dependency injection container
     * @param iterable<class-string<Command>>     $commands  Explicitly passed command classes
     * @throws ReflectionException
     */
    public function __construct(
        private readonly ContainerInterface $container,
        iterable $commands = [],
    ) {

        // 1. Register explicitly passed commands
        $this->registerAll($commands, 'explicit');

        // 2. Auto-discover vendor commands (MonkeysLegion\Cli\Command\*)
        $this->discoverVendorCommands();

        // 3. Auto-discover application commands
        $this->discoverAppCommands();

        // 4. Build grouped display
        $this->buildGroupedCommands();

        // 5. Display any loading errors
        if ($this->loadingErrors !== []) {
            $this->displayLoadingErrors();
        }
    }

    /**
     * Execute a command from argv.
     *
     * @param list<string> $argv
     * @return int Exit code
     */
    public function run(array $argv): int
    {
        $sig = $argv[1] ?? 'list';

        // Built-in commands
        if ($sig === 'list' || $sig === 'help') {
            $this->displayCommandList();

            return 0;
        }

        // Prefix group listing (e.g., `make:`)
        if (str_ends_with($sig, ':') && isset($this->groupedCommands[rtrim($sig, ':')])) {
            $this->displayPrefixCommands(rtrim($sig, ':'));

            return 0;
        }

        // Resolve alias → canonical
        $canonical = $this->aliases[$sig] ?? $sig;

        if (!isset($this->map[$canonical])) {
            $this->cliLine()
                ->add("Command '", 'red')
                ->add($sig, 'yellow', 'bold')
                ->add("' not found.", 'red')
                ->printError();

            $this->suggestSimilarCommands($sig);

            return 1;
        }

        try {
            /** @var Command $command */
            $command = $this->container->get($this->map[$canonical]);

            return $command();
        } catch (\Throwable $e) {
            $this->cliLine()
                ->add('❌ Error executing command: ', 'red', 'bold')
                ->add($e->getMessage(), 'white')
                ->printError();

            if (getenv('APP_DEBUG') === 'true') {
                $this->cliLine()
                    ->add('Stack trace:', 'gray')
                    ->printError();
                fwrite(STDERR, $e->getTraceAsString() . "\n");
            }

            return 1;
        }
    }

    // ── Registration ──────────────────────────────────────────────

    /**
     * @param iterable<class-string<Command>> $commands
     */
    private function registerAll(iterable $commands, string $source): void
    {
        try {
            foreach ($commands as $class) {
                try {
                    $this->register($class);
                } catch (\Throwable $e) {
                    $this->loadingErrors[] = "Failed to register {$source} command '{$class}': {$e->getMessage()}";
                }
            }
        } catch (\Throwable $e) {
            $this->loadingErrors[] = "Error loading {$source} commands: {$e->getMessage()}";
        }
    }

    /**
     * Register a single command class.
     *
     * @param class-string<Command> $class
     */
    private function register(string $class): void
    {
        $ref   = new ReflectionClass($class);
        $attrs = $ref->getAttributes(CommandAttr::class);

        if ($attrs === []) {
            return;
        }

        /** @var CommandAttr $meta */
        $meta = $attrs[0]->newInstance();

        // Register primary signature
        $this->map[$meta->signature]          = $class;
        $this->descriptions[$meta->signature] = $meta->description;

        if ($meta->hidden) {
            $this->hidden[$meta->signature] = true;
        }

        // Register aliases
        foreach ($meta->aliases as $alias) {
            $this->aliases[$alias] = $meta->signature;
        }
    }

    // ── Discovery ─────────────────────────────────────────────────

    private function discoverVendorCommands(): void
    {
        try {
            foreach (glob(__DIR__ . '/Command/*.php') ?: [] as $file) {
                try {
                    require_once $file;
                } catch (\Throwable $e) {
                    $this->loadingErrors[] = 'Failed to load vendor command file \'' . basename($file) . "': {$e->getMessage()}";
                }
            }

            /** @var list<class-string<Command>> $vendorClasses */
            $vendorClasses = array_filter(
                get_declared_classes(),
                static fn(string $c): bool => str_starts_with($c, 'MonkeysLegion\\Cli\\Command\\')
                    && is_subclass_of($c, Command::class),
            );

            $this->registerAll($vendorClasses, 'vendor');
        } catch (\Throwable $e) {
            $this->loadingErrors[] = "Error discovering vendor commands: {$e->getMessage()}";
        }
    }

    private function discoverAppCommands(): void
    {
        // Support both App\Command\* (v2 convention) and App\Cli\Command\* (v1)
        $dirs = [
            'app/Command'     => 'App\\Command\\',
            'app/Cli/Command' => 'App\\Cli\\Command\\',
        ];

        foreach ($dirs as $relPath => $ns) {
            if (!function_exists('base_path')) {
                continue;
            }
            $dir = base_path($relPath);

            if (!is_dir($dir)) {
                continue;
            }

            try {
                foreach (glob($dir . '/*.php') ?: [] as $file) {
                    try {
                        require_once $file;
                    } catch (\Throwable $e) {
                        $this->loadingErrors[] = 'Failed to load app command file \'' . basename($file) . "': {$e->getMessage()}";
                    }
                }

                /** @var list<class-string<Command>> $appClasses */
                $appClasses = array_filter(
                    get_declared_classes(),
                    static fn(string $c): bool => str_starts_with($c, $ns)
                        && is_subclass_of($c, Command::class),
                );

                $this->registerAll($appClasses, 'app');
            } catch (\Throwable $e) {
                $this->loadingErrors[] = "Error discovering app commands from {$relPath}: {$e->getMessage()}";
            }
        }
    }

    // ── Grouping & display ────────────────────────────────────────

    private function buildGroupedCommands(): void
    {
        foreach ($this->map as $signature => $class) {
            // Skip hidden commands
            if (isset($this->hidden[$signature])) {
                continue;
            }

            if (str_contains($signature, ':')) {
                [$prefix, $name] = explode(':', $signature, 2);
                $this->groupedCommands[$prefix][$name] = $class;
            } else {
                $this->groupedCommands['general'][$signature] = $class;
            }
        }

        ksort($this->groupedCommands);

        foreach ($this->groupedCommands as &$commands) {
            ksort($commands);
        }
    }

    private function displayCommandList(): void
    {
        $this->cliLine()
            ->add('  MonkeysLegion CLI ', 'green', 'bold')
            ->add('v2.0', 'cyan')
            ->print();

        echo str_repeat('─', 70) . "\n";

        foreach ($this->groupedCommands as $prefix => $commands) {
            $label = $prefix === 'general' ? 'General' : ucfirst($prefix);

            $this->cliLine()
                ->plain("\n")
                ->add("  {$label}:", 'yellow', 'bold')
                ->print();

            foreach ($commands as $name => $class) {
                $sig  = $prefix === 'general' ? $name : "{$prefix}:{$name}";
                $desc = $this->descriptions[$sig] ?? '';

                $this->cliLine()
                    ->add('    ')
                    ->add(str_pad($sig, 28), 'cyan')
                    ->add($desc, 'white')
                    ->print();
            }
        }

        echo "\n" . str_repeat('─', 70) . "\n";

        $this->cliLine()
            ->add("  Run '", 'white')
            ->add('command-name', 'white', 'bold')
            ->add("' to execute.  Run '", 'white')
            ->add('prefix:', 'cyan')
            ->add("' to filter by group.", 'white')
            ->print();

        echo "\n";
    }

    private function displayPrefixCommands(string $prefix): void
    {
        if (!isset($this->groupedCommands[$prefix])) {
            $this->cliLine()
                ->add("No commands found with prefix '", 'red')
                ->add($prefix, 'yellow', 'bold')
                ->add("'", 'red')
                ->print();

            return;
        }

        $this->cliLine()
            ->add("  {$prefix} Commands:", 'green', 'bold')
            ->print();

        echo str_repeat('─', 70) . "\n\n";

        foreach ($this->groupedCommands[$prefix] as $name => $class) {
            $sig  = "{$prefix}:{$name}";
            $desc = $this->descriptions[$sig] ?? '';

            $this->cliLine()
                ->add('    ')
                ->add(str_pad($sig, 28), 'cyan')
                ->add($desc, 'white')
                ->print();
        }

        echo "\n" . str_repeat('─', 70) . "\n\n";
    }

    private function suggestSimilarCommands(string $input): void
    {
        $suggestions = [];
        $lower       = strtolower($input);

        // Search both primary signatures and aliases
        $allNames = array_merge(array_keys($this->map), array_keys($this->aliases));

        foreach ($allNames as $name) {
            $nameLower = strtolower($name);

            if (str_starts_with($nameLower, $lower) || levenshtein($lower, $nameLower) <= 3) {
                $suggestions[] = $name;
            }
        }

        if ($suggestions !== []) {
            $this->cliLine()
                ->add("\nDid you mean one of these?", 'yellow')
                ->printError();

            foreach (array_unique($suggestions) as $suggestion) {
                $this->cliLine()
                    ->add('  • ', 'yellow')
                    ->add($suggestion, 'cyan')
                    ->printError();
            }
        }

        $this->cliLine()
            ->add("\nRun '", 'white')
            ->add('list', 'cyan', 'bold')
            ->add("' to see all available commands.", 'white')
            ->printError();

        echo "\n";
    }

    private function displayLoadingErrors(): void
    {
        $this->cliLine()
            ->add('⚠️  Command Loading Warnings:', 'yellow', 'bold')
            ->printError();

        foreach ($this->loadingErrors as $error) {
            $this->cliLine()
                ->add('  • ', 'yellow')
                ->add($error, 'white')
                ->printError();
        }

        $this->cliLine()->plain('')->printError();
    }
}
