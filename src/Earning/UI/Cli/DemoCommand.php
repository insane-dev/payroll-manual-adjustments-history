<?php

declare(strict_types=1);

namespace App\Earning\UI\Cli;

use App\Earning\Application\Command\AddManualAdjustment;
use App\Earning\Application\Command\CreateEarningLine;
use App\Earning\Application\Command\Handler\AddManualAdjustmentHandler;
use App\Earning\Application\Command\Handler\CreateEarningLineHandler;
use App\Earning\Application\Command\Handler\UpdateSystemAmountHandler;
use App\Earning\Application\Command\UpdateSystemAmount;
use App\Earning\Application\Query\GetAdjustmentHistory;
use App\Earning\Application\Query\Handler\GetAdjustmentHistoryHandler;
use App\Earning\Application\Query\Result\AdjustmentHistory;
use App\Earning\Domain\Value\AdjustmentAuthorId;
use App\Earning\Domain\Value\EarningLineId;
use App\Earning\Domain\Value\ManualAdjustmentId;
use InvalidArgumentException;
use Money\Currencies\ISOCurrencies;
use Money\Formatter\DecimalMoneyFormatter;
use Money\Money;
use Ramsey\Uuid\Uuid;
use Throwable;

final readonly class DemoCommand
{
    public function __construct(
        private CreateEarningLineHandler $createEarningLine,
        private UpdateSystemAmountHandler $updateSystemAmount,
        private AddManualAdjustmentHandler $addManualAdjustment,
        private GetAdjustmentHistoryHandler $getAdjustmentHistory,
    ) {
    }

    /** @param list<string> $arguments */
    public function run(array $arguments): int
    {
        try {
            if (count($arguments) !== 0 && !(count($arguments) === 2 && $arguments[0] === '--history')) {
                throw new InvalidArgumentException('Usage: php bin/demo.php [--history <earning-line-id>]');
            }

            $query = $this->getAdjustmentHistory;
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

            if (count($arguments) === 2) {
                $printHistory($query->handle(new GetAdjustmentHistory(new EarningLineId($arguments[1]))));
                return 0;
            }

            $create = $this->createEarningLine;
            $update = $this->updateSystemAmount;
            $add = $this->addManualAdjustment;
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

            return 0;
        } catch (Throwable $exception) {
            fwrite(STDERR, $exception->getMessage() . PHP_EOL);
            return 1;
        }
    }
}
