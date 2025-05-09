<?php

declare(strict_types=1);

namespace MonkeysLegion\Cli;

use MonkeysLegion\Cli\Console\Command;
use MonkeysLegion\Cli\Console\Attributes\Command as CommandAttr;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use ReflectionClass;
use ReflectionException;

final class CliKernel
{
    /** @var array<string, class-string<Command>> */
    private array $map = [];

    /**
     * @throws ReflectionException
     */
    public function __construct(private ContainerInterface $container)
    {
        // Discover all classes in MonkeysLegion\Cli\Command namespace
        // (composer-classmap is fine for now—no runtime filesystem scan).
        foreach (get_declared_classes() as $class) {
            if (!str_starts_with($class, 'MonkeysLegion\\Cli\\Command\\')) continue;

            $ref = new ReflectionClass($class);
            $attr = $ref->getAttributes(CommandAttr::class)[0] ?? null;
            if (!$attr) continue;

            /** @var CommandAttr $meta */
            $meta = $attr->newInstance();
            $this->map[$meta->signature] = $class;
        }
    }

    /**
     * Resolve and execute a command.
     * argv[1] = signature, argv[2..] = raw arguments (ignored for now)
     * @throws ReflectionException
     */
    public function run(array $argv): int
    {
        $sig = $argv[1] ?? 'list';

        if ($sig === 'list') {
            echo "Available commands:\n";
            foreach ($this->map as $signature => $cls) {
                $desc = new ReflectionClass($cls)
                    ->getAttributes(CommandAttr::class)[0]
                    ->newInstance()->description;
                echo "  $signature  -  $desc\n";
            }
            return 0;
        }

        if (!isset($this->map[$sig])) {
            fwrite(STDERR, "Command '$sig' not found.\n");
            return 1;
        }

        /** @var Command $cmd */
        try {
            $cmd = $this->container->get($this->map[$sig]);
        } catch (NotFoundExceptionInterface|ContainerExceptionInterface $e) {

        }  // constructor-injected
        return $cmd();
    }
}