<?php

declare(strict_types=1);

namespace App\Tests\Acceptance\Earning\UI;

use App\Tests\Shared\Infrastructure\Persist\MysqlTestDatabase;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class DemoTest extends TestCase
{
    public function testTheCliDemonstratesTheScenarioAndCanReadItInAnotherProcess(): void
    {
        $database = new MysqlTestDatabase();
        try {
            [$status, $output, $errors] = $this->runCli($database);
            self::assertSame(0, $status, $errors);
            self::assertSame('', $errors);
            self::assertStringContainsString('Step 4: System update ignored => USD 1004.45', $output);
            self::assertStringContainsString('Step 8: Compensating adjustment => USD 1104.45', $output);
            self::assertStringContainsString('System Amount (Frozen): USD 1050.00', $output);
            self::assertStringContainsString('Current Amount: USD 1104.45', $output);
            self::assertStringContainsString('Initial Adjustment: USD 1000.00', $output);
            self::assertStringContainsString('Correcting mistake in adjustment #4', $output);
            self::assertSame(1, preg_match('/Earning Line: ([a-f0-9-]+)/', $output, $matches));

            [$status, $history, $errors] = $this->runCli($database, '--history', $matches[1]);
            self::assertSame(0, $status, $errors);
            self::assertStringContainsString('Current Amount: USD 1104.45', $history);
            self::assertStringNotContainsString('Step 1:', $history);
            self::assertSame(7, substr_count($history, 'Recorded At:'));

            $connection = $database->connection();
            $query = $connection->query('SELECT COUNT(*) FROM earning_line_adjustments');
            if ($query === false) {
                throw new RuntimeException('Cannot query adjustment history.');
            }
            self::assertSame(7, (int) $query->fetchColumn());
            unset($connection);
        } finally {
            $database->drop();
        }
    }

    /** @return array{int, string, string} */
    private function runCli(MysqlTestDatabase $database, string ...$arguments): array
    {
        $process = proc_open(
            [PHP_BINARY, __DIR__ . '/../../../../bin/demo.php', ...array_values($arguments)],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            env_vars: $database->environment(),
        );
        if ($process === false) {
            throw new RuntimeException('Cannot start the CLI process.');
        }
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $output, $errors];
    }
}
