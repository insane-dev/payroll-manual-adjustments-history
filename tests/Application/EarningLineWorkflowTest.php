<?php

declare(strict_types=1);

namespace Alcor\Payroll\Tests\Application;

use Alcor\Payroll\Application\AddManualAdjustment;
use Alcor\Payroll\Application\AddManualAdjustmentHandler;
use Alcor\Payroll\Application\Clock;
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
use Alcor\Payroll\Infrastructure\EventSerializer;
use Alcor\Payroll\Infrastructure\SqliteEventStore;
use Carbon\CarbonImmutable;
use DomainException;
use Money\Money;
use PDO;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

final class EarningLineWorkflowTest extends TestCase
{
    public function testTheAssignmentRunsThroughCommandsAndReturnsTheCompleteSavedHistory(): void
    {
        $store = new SqliteEventStore(new PDO('sqlite::memory:'), new EventSerializer());
        $repository = new EarningLineRepository($store);
        $clock = new class implements Clock {
            public function now(): CarbonImmutable
            {
                return CarbonImmutable::parse('2026-09-15T12:00:00.123456Z');
            }
        };
        $create = new CreateEarningLineHandler($repository, $clock);
        $update = new UpdateSystemAmountHandler($repository, $clock);
        $add = new AddManualAdjustmentHandler($repository, $clock);
        $query = new GetAdjustmentHistoryHandler($repository);
        $id = new EarningLineId(Uuid::uuid4()->toString());
        $authorId = new AdjustmentAuthorId(Uuid::uuid4()->toString());
        $request = new GetAdjustmentHistory($id);

        $create->handle(new CreateEarningLine($id, Money::USD('100000')));
        self::assertSame('100000', $query->handle($request)->currentAmount->getAmount());
        $update->handle(new UpdateSystemAmount($id, Money::USD('105000')));
        self::assertSame('105000', $query->handle($request)->currentAmount->getAmount());

        $firstId = new ManualAdjustmentId(Uuid::uuid4()->toString());
        $firstCommand = new AddManualAdjustment($id, $firstId, Money::USD('-4555'), 'Employee declined dental benefit; reversing deduction', $authorId);
        $add->handle($firstCommand);
        $firstHistory = $query->handle($request);
        self::assertSame('100445', $firstHistory->currentAmount->getAmount());

        $update->handle(new UpdateSystemAmount($id, Money::USD('999999')));
        self::assertSame('100445', $query->handle($request)->currentAmount->getAmount());
        self::assertCount(3, $store->load($id));

        foreach ([
            ['10010', 'Late correction: missed approved overtime bonus', '110455'],
            ['-10', 'Minor rounding adjustment', '110445'],
            ['-20', 'Second minor rounding adjustment', '110425'],
            ['20', 'Correcting mistake in adjustment #4', '110445'],
        ] as [$amount, $comment, $expected]) {
            $add->handle(new AddManualAdjustment($id, new ManualAdjustmentId(Uuid::uuid4()->toString()), Money::USD($amount), $comment, $authorId));
            self::assertSame($expected, $query->handle($request)->currentAmount->getAmount());
        }

        $history = $query->handle($request);
        self::assertSame('105000', $history->systemAmount->getAmount());
        self::assertCount(5, $history->adjustments);
        self::assertCount(7, $store->load($id));
        self::assertSame($firstId->value, $history->adjustments[0]->id->value);
        self::assertSame($authorId->value, $history->adjustments[0]->authorId->value);
        self::assertSame('Employee declined dental benefit; reversing deduction', $history->adjustments[0]->comment);
        self::assertSame('2026-09-15T12:00:00.123456+00:00', $history->adjustments[0]->recordedAt->format('Y-m-d\TH:i:s.uP'));
        self::assertSame('Correcting mistake in adjustment #4', $history->adjustments[4]->comment);
        self::assertCount(1, $firstHistory->adjustments);
        self::assertSame('100445', $firstHistory->currentAmount->getAmount());

        try {
            $add->handle($firstCommand);
            self::fail('The same adjustment command was applied twice.');
        } catch (DomainException) {
            self::assertSame('110445', $query->handle($request)->currentAmount->getAmount());
            self::assertCount(7, $store->load($id));
        }
    }
}
