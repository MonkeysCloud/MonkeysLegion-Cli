<?php
declare(strict_types=1);

namespace MonkeysLegion\Cli;

use MonkeysLegion\Cli\Console\Attributes\Command as CommandAttr;
use MonkeysLegion\DI\Container as DIContainer;
use ReflectionClass;
use ReflectionException;

/**
 * Attribute-driven CLI kernel.
 * Scans all classes under MonkeysLegion\Cli\Command for #[Command(...)] and builds a map.
 */
final class CliKernel
{
    /** @var array<string, class-string> */
    private array $map = [];

    /**
     * @param DIContainer $container DI container for resolving commands
     * @throws ReflectionException
     */
    public function __construct(private DIContainer $container)
    {
        // Discover all command classes tagged with #[CommandAttr]
        foreach (get_declared_classes() as $class) {
            if (! str_starts_with($class, 'MonkeysLegion\\Cli\\Command\\')) {
                continue;
            }
            $ref = new ReflectionClass($class);
            $attrs = $ref->getAttributes(CommandAttr::class);
            if (empty($attrs)) {
                continue;
            }
            /** @var CommandAttr $meta */
            $meta = $attrs[0]->newInstance();
            $this->map[$meta->signature] = $class;
        }
    }

    /**
     * Execute the CLI command based on argv.
     *
     * @param string[] $argv
     * @return int Exit code
     */
    public function run(array $argv): int
    {
        $sig = $argv[1] ?? 'list';

        if ($sig === 'list') {
            echo "Available commands:\n";
            foreach ($this->map as $signature => $class) {
                $desc = (new ReflectionClass($class))
                    ->getAttributes(CommandAttr::class)[0]
                    ->newInstance()
                    ->description;
                echo "  {$signature}  -  {$desc}\n";
            }
            return 0;
        }

        if (! isset($this->map[$sig])) {
            fwrite(STDERR, "Command '{$sig}' not found.\n");
            return 1;
        }

        // Resolve the command from the container and invoke it
        $cmdClass = $this->map[$sig];
        $command  = $this->container->get($cmdClass);
        return $command();
    }
}
