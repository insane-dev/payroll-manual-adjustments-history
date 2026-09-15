<?php

declare(strict_types=1);

use Alcor\Payroll\Application\AddManualAdjustment;
use Alcor\Payroll\Application\AddManualAdjustmentHandler;
use Alcor\Payroll\Application\AdjustmentHistory;
use Alcor\Payroll\Application\CreateEarningLine;
use Alcor\Payroll\Application\CreateEarningLineHandler;
use Alcor\Payroll\Application\EarningLineRepository;
use Alcor\Payroll\Application\GetAdjustmentHistory;
use Alcor\Payroll\Application\GetAdjustmentHistoryHandler;
use Alcor\Payroll\Application\UpdateSystemAmount;
use Alcor\Payroll\Application\UpdateSystemAmountHandler;
use Alcor\Payroll\Domain\AdjustmentAuthorId;
use Alcor\Payroll\Domain\EarningLineId;
use Alcor\Payroll\Domain\ManualAdjustmentId;
use Alcor\Payroll\Infrastructure\CarbonClock;
use Alcor\Payroll\Infrastructure\EventSerializer;
use Alcor\Payroll\Infrastructure\SqliteEventStore;
use Money\Currencies\ISOCurrencies;
use Money\Formatter\DecimalMoneyFormatter;
use Money\Money;
use Ramsey\Uuid\Uuid;

require __DIR__ . '/../vendor/autoload.php';

try {
    if ($argc !== 1 && !($argc === 3 && $argv[1] === '--history')) {
        throw new InvalidArgumentException('Usage: php bin/demo.php [--history <earning-line-id>]');
    }

    $database = getenv('PAYROLL_DATABASE') ?: __DIR__ . '/../var/payroll.sqlite';
    $directory = dirname($database);
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException('Cannot create the database directory.');
    }

    // Composition root: all dependencies are explicit, with no container or service locator.
    $repository = new EarningLineRepository(new SqliteEventStore(new PDO('sqlite:' . $database), new EventSerializer()));
    $query = new GetAdjustmentHistoryHandler($repository);
    $formatter = new DecimalMoneyFormatter(new ISOCurrencies());
    $format = static fn (Money $amount): string => $amount->getCurrency()->getCode() . ' ' . $formatter->format($amount);

    $printHistory = static function (AdjustmentHistory $history) use ($format): void {
        echo 'Earning Line: ' . $history->earningLineId->value . PHP_EOL;
        echo ($history->adjustments === [] ? 'System Amount: ' : 'System Amount (Frozen): ') . $format($history->systemAmount) . PHP_EOL;
        foreach ($history->adjustments as $index => $adjustment) {
            printf("Adjustment %d: %s%s\n", $index + 1, $adjustment->amount->isPositive() ? '+' : '', $format($adjustment->amount));
            echo '  Id: ' . $adjustment->id->value . PHP_EOL;
            echo '  Author: ' . $adjustment->authorId->value . PHP_EOL;
            echo '  Recorded At: ' . $adjustment->recordedAt->format('Y-m-d\TH:i:s.uP') . PHP_EOL;
            echo '  Comment: ' . $adjustment->comment . PHP_EOL;
        }
        echo 'Current Amount: ' . $format($history->currentAmount) . PHP_EOL;
    };

    if ($argc === 3) {
        $printHistory($query->handle(new GetAdjustmentHistory(new EarningLineId($argv[2]))));
        exit(0);
    }

    $clock = new CarbonClock();
    $create = new CreateEarningLineHandler($repository, $clock);
    $update = new UpdateSystemAmountHandler($repository, $clock);
    $add = new AddManualAdjustmentHandler($repository, $clock);
    $id = new EarningLineId(Uuid::uuid4()->toString());
    $authorId = new AdjustmentAuthorId(Uuid::uuid4()->toString());
    $request = new GetAdjustmentHistory($id);

    $step = static function (int $number, string $description) use ($query, $request, $format): void {
        printf("Step %d: %s => %s\n", $number, $description, $format($query->handle($request)->currentAmount));
    };
    $adjust = static function (string $amount, string $comment) use ($add, $id, $authorId): void {
        $add->handle(new AddManualAdjustment($id, new ManualAdjustmentId(Uuid::uuid4()->toString()), Money::USD($amount), $comment, $authorId));
    };

    $create->handle(new CreateEarningLine($id, Money::USD('100000')));
    $step(1, 'System calculation');
    $update->handle(new UpdateSystemAmount($id, Money::USD('105000')));
    $step(2, 'System update');
    $adjust('-4555', 'Employee declined dental benefit; reversing deduction');
    $step(3, 'First manual adjustment');
    $update->handle(new UpdateSystemAmount($id, Money::USD('200000')));
    $step(4, 'System update ignored');
    $adjust('10010', 'Late correction: missed approved overtime bonus');
    $step(5, 'Second manual adjustment');
    $adjust('-10', 'Minor rounding adjustment');
    $step(6, 'Third manual adjustment');
    $adjust('-20', 'Second minor rounding adjustment');
    $step(7, 'Fourth manual adjustment');
    $adjust('20', 'Correcting mistake in adjustment #4');
    $step(8, 'Compensating adjustment');

    echo PHP_EOL;
    $printHistory($query->handle($request));
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}
