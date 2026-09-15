<?php

declare(strict_types=1);

namespace App\Tests\Integration\Earning\Application;

use App\Earning\Application\Command\AddManualAdjustment;
use App\Earning\Application\Command\CreateEarningLine;
use App\Earning\Application\Command\Handler\AddManualAdjustmentHandler;
use App\Earning\Application\Command\Handler\CreateEarningLineHandler;
use App\Earning\Application\Command\Handler\UpdateSystemAmountHandler;
use App\Earning\Application\Command\UpdateSystemAmount;
use App\Earning\Application\Query\GetAdjustmentHistory;
use App\Earning\Application\Query\Handler\GetAdjustmentHistoryHandler;
use App\Earning\Domain\Value\AdjustmentAuthorId;
use App\Earning\Domain\Value\EarningLineAdjustmentId;
use App\Earning\Domain\Value\EarningLineId;
use App\Earning\Infrastructure\Persist\Write\SqliteEarningLineRepository;
use App\Shared\Application\Clock;
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
        $repository = new SqliteEarningLineRepository(new PDO('sqlite::memory:'));
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

        $firstId = new EarningLineAdjustmentId(Uuid::uuid4()->toString());
        $firstCommand = new AddManualAdjustment($id, $firstId, Money::USD('-4555'), 'Employee declined dental benefit; reversing deduction', $authorId);
        $add->handle($firstCommand);
        $firstHistory = $query->handle($request);
        self::assertSame('100445', $firstHistory->currentAmount->getAmount());

        $update->handle(new UpdateSystemAmount($id, Money::USD('999999')));
        self::assertSame('100445', $query->handle($request)->currentAmount->getAmount());
        $afterIgnoredUpdate = $repository->get($id);
        self::assertCount(3, $afterIgnoredUpdate->adjustments());

        foreach ([
            ['10010', 'Late correction: missed approved overtime bonus', '110455'],
            ['-10', 'Minor rounding adjustment', '110445'],
            ['-20', 'Second minor rounding adjustment', '110425'],
            ['20', 'Correcting mistake in adjustment #4', '110445'],
        ] as [$amount, $comment, $expected]) {
            $add->handle(new AddManualAdjustment($id, new EarningLineAdjustmentId(Uuid::uuid4()->toString()), Money::USD($amount), $comment, $authorId));
            self::assertSame($expected, $query->handle($request)->currentAmount->getAmount());
        }

        $history = $query->handle($request);
        self::assertSame('105000', $history->systemAmount->getAmount());
        self::assertCount(7, $history->adjustments);
        $savedLine = $repository->get($id);
        self::assertCount(7, $savedLine->adjustments());
        self::assertSame($firstId->value, $history->adjustments[2]->id->value);
        self::assertNotNull($history->adjustments[2]->authorId);
        self::assertSame($authorId->value, $history->adjustments[2]->authorId->value);
        self::assertSame('Employee declined dental benefit; reversing deduction', $history->adjustments[2]->comment);
        self::assertSame('2026-09-15T12:00:00.123456+00:00', $history->adjustments[2]->recordedAt->format('Y-m-d\TH:i:s.uP'));
        self::assertSame('Correcting mistake in adjustment #4', $history->adjustments[6]->comment);
        self::assertCount(3, $firstHistory->adjustments);
        self::assertSame('100445', $firstHistory->currentAmount->getAmount());

        try {
            $add->handle($firstCommand);
            self::fail('The same adjustment command was applied twice.');
        } catch (DomainException) {
            self::assertSame('110445', $query->handle($request)->currentAmount->getAmount());
            $afterDuplicateAttempt = $repository->get($id);
            self::assertCount(7, $afterDuplicateAttempt->adjustments());
        }
    }
}
