<?php

declare(strict_types=1);

use App\Earning\Application\Command\Handler\AddManualAdjustmentHandler;
use App\Earning\Application\Command\Handler\CreateEarningLineHandler;
use App\Earning\Application\Command\Handler\UpdateSystemAmountHandler;
use App\Earning\Application\Query\Handler\GetAdjustmentHistoryHandler;
use App\Earning\Infrastructure\Persist\Write\MysqlEarningLineRepository;
use App\Earning\Infrastructure\Persist\Write\MysqlSchema;
use App\Earning\UI\Cli\DemoCommand;
use App\Shared\Infrastructure\Clock\CarbonClock;
use App\Shared\Infrastructure\Persist\MysqlConnectionFactory;

require __DIR__ . '/../vendor/autoload.php';

try {
    // Composition root: infrastructure is wired here, outside the UI command.
    $connection = (new MysqlConnectionFactory())->create(
        getenv('PAYROLL_DATABASE_DSN') ?: 'mysql:host=mysql;dbname=payroll;charset=utf8mb4',
        getenv('PAYROLL_DATABASE_USER') ?: 'payroll',
        getenv('PAYROLL_DATABASE_PASSWORD') ?: 'payroll',
    );
    (new MysqlSchema())->initialize($connection);
    $earningLines = new MysqlEarningLineRepository($connection);
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
