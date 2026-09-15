<?php

declare(strict_types=1);

use App\Earning\Application\Command\Handler\AddManualAdjustmentHandler;
use App\Earning\Application\Command\Handler\CreateEarningLineHandler;
use App\Earning\Application\Command\Handler\UpdateSystemAmountHandler;
use App\Earning\Application\Query\Handler\GetAdjustmentHistoryHandler;
use App\Earning\Infrastructure\Persist\Write\SqliteEarningLineRepository;
use App\Earning\UI\Cli\DemoCommand;
use App\Shared\Infrastructure\Clock\CarbonClock;

require __DIR__ . '/../vendor/autoload.php';

try {
    $database = getenv('PAYROLL_DATABASE') ?: __DIR__ . '/../var/payroll-no-es-compact.sqlite';
    $directory = dirname($database);
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException('Cannot create the database directory.');
    }

    // Composition root: infrastructure is wired here, outside the UI command.
    $earningLines = new SqliteEarningLineRepository(new PDO('sqlite:' . $database));
    $clock = new CarbonClock();
    $command = new DemoCommand(
        new CreateEarningLineHandler($earningLines, $clock),
        new UpdateSystemAmountHandler($earningLines, $clock),
        new AddManualAdjustmentHandler($earningLines, $clock),
        new GetAdjustmentHistoryHandler($earningLines),
    );

    exit($command->run(array_slice($argv, 1)));
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}
