<?php
declare(strict_types=1);

namespace MonkeysLegion\Cli\Command;

use MonkeysLegion\Cli\Console\Attributes\Command as CommandAttr;
use MonkeysLegion\Cli\Console\Command;
use MonkeysLegion\DI\ContainerBuilder;
use Psr\Container\ContainerInterface;

#[CommandAttr(
    'tinker',
    'Interactive REPL (Tinker) with Container bootstrapped'
)]
final class TinkerCommand extends Command
{
    public function handle(): int
    {
        // 1) Bootstrap your DI container
        $builder = new ContainerBuilder();
        $builder->addDefinitions(require base_path('config/app.php'));
        /** @var ContainerInterface $container */
        $container = $builder->build();

        // Mark $container as “used” & inject it into the local scope
        extract(['container' => $container]);

        // 2) Welcome message
        echo "MonkeysLegion Tinker\n";
        echo "Type PHP expressions to evaluate. Available variable: \$container\n";
        echo "Type exit or quit to leave.\n\n";

        // 3) REPL loop
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

            // 4) Evaluate and dump result
            try {
                $toEval = $this->wrapExpression($code);
                /** @noinspection PhpEval */
                $result = eval($toEval);
                var_dump($result);
            } catch (\Throwable $e) {
                echo 'Error: ' . $e->getMessage() . "\n";
            }
        }

        return self::SUCCESS;
    }

    private function wrapExpression(string $code): string
    {
        $trimmed = rtrim($code);
        if (str_ends_with($trimmed, ';')) {
            return $trimmed;
        }
        return 'return ' . $trimmed . ';';
    }
}