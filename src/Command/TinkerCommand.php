<?php
declare(strict_types=1);

namespace MonkeysLegion\Cli\Command;

use MonkeysLegion\Cli\Console\Attributes\Command as CommandAttr;
use MonkeysLegion\Cli\Console\Command;
use Psr\Container\ContainerInterface;

/**
 * MonkeysLegion Framework — CLI Package
 *
 * Interactive REPL with the DI container bootstrapped.
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
#[CommandAttr('tinker', 'Interactive REPL with the DI Container')]
final class TinkerCommand extends Command
{
    public function __construct(
        private readonly ContainerInterface $container,
    ) {
        parent::__construct();
    }

    protected function handle(): int
    {
        // Inject container into local scope for REPL access
        $container = $this->container;

        $this->cliLine()
            ->add('MonkeysLegion Tinker', 'cyan', 'bold')
            ->print();
        $this->comment('Available: $container. Type "exit" to quit.');
        $this->newLine();

        // REPL loop
        while (true) {
            $prompt = 'ml> ';

            if (function_exists('readline')) {
                $line = readline($prompt);

                if ($line === false) {
                    echo "\n";
                    break;
                }

                readline_add_history($line);
            } else {
                echo $prompt;
                $line = fgets(STDIN);

                if ($line === false) {
                    break;
                }
            }

            $code = trim($line);

            if ($code === '' || in_array(strtolower($code), ['exit', 'quit'], true)) {
                break;
            }

            try {
                $toEval = str_ends_with(rtrim($code), ';') ? $code : "return {$code};";
                $result = eval($toEval);
                var_dump($result);
            } catch (\Throwable $e) {
                $this->error("Error: {$e->getMessage()}");
            }
        }

        return self::SUCCESS;
    }
}
