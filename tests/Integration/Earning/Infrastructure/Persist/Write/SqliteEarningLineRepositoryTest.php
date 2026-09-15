<?php

declare(strict_types=1);

namespace App\Tests\Integration\Earning\Infrastructure\Persist\Write;

use App\Earning\Application\Exception\ConcurrentStreamWrite;
use App\Earning\Application\Exception\EarningLineNotFound;
use App\Earning\Domain\Entity\EarningLine;
use App\Earning\Domain\Entity\EarningLineAdjustment;
use App\Earning\Domain\Repository\EarningLineRepository;
use App\Earning\Domain\Value\AdjustmentAuthorId;
use App\Earning\Domain\Value\EarningLineId;
use App\Earning\Domain\Value\EarningLineAdjustmentId;
use App\Earning\Infrastructure\Persist\Write\SqliteEarningLineRepository;
use Carbon\CarbonImmutable;
use PDO;
use PDOException;
use Money\Money;
use OverflowException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

final class SqliteEarningLineRepositoryTest extends TestCase
{
    private string $database;
    private PDO $connection;
    private EarningLineRepository $repository;

    protected function setUp(): void
    {
        $this->database = tempnam(sys_get_temp_dir(), 'alcor-');
        $this->connection = new PDO('sqlite:' . $this->database);
        $this->repository = new SqliteEarningLineRepository($this->connection);
    }

    protected function tearDown(): void
    {
        unset($this->repository, $this->connection);
        unlink($this->database);
    }

    public function testStateAndAdjustmentsSurviveReopeningTheDatabaseWithExactAmountsAndAuditMetadata(): void
    {
        $line = $this->line();
        $line->updateSystemAmount(Money::USD('9223372036854775806'), $this->now());
        $adjustment = $this->adjustment('1');
        $line->addManualAdjustment($adjustment);
        $this->repository->save($line);
        self::assertSame([], $line->pendingAdjustments());

        $reopened = new SqliteEarningLineRepository(new PDO('sqlite:' . $this->database));
        $restored = $reopened->get($line->id());

        self::assertSame($line->id()->value, $restored->id()->value);
        self::assertSame('9223372036854775806', $restored->systemAmount()->getAmount());
        self::assertSame('9223372036854775807', $restored->currentAmount()->getAmount());
        self::assertEquals($line->adjustments(), $restored->adjustments());
        self::assertSame('2026-09-15T10:00:00.123456+00:00', $restored->adjustments()[0]->recordedAt->format('Y-m-d\TH:i:s.uP'));
        self::assertSame(3, $restored->version());
        self::assertSame([], $restored->pendingAdjustments());
    }

    #[DataProvider('racingWrites')]
    public function testAStaleWriterCannotOverwriteAnAcceptedChange(bool $adjustmentWins): void
    {
        $line = $this->line();
        $this->repository->save($line);
        $first = $this->repository->get($line->id());
        $secondRepository = new SqliteEarningLineRepository(new PDO('sqlite:' . $this->database));
        $stale = $secondRepository->get($line->id());

        if ($adjustmentWins) {
            $first->addManualAdjustment($this->adjustment('-4555'));
            $stale->updateSystemAmount(Money::USD('105000'), $this->now());
        } else {
            $first->updateSystemAmount(Money::USD('105000'), $this->now());
            $stale->addManualAdjustment($this->adjustment('-4555'));
        }
        $this->repository->save($first);

        try {
            $secondRepository->save($stale);
            self::fail('A stale writer was accepted.');
        } catch (ConcurrentStreamWrite) {
            self::assertCount(1, $stale->pendingAdjustments());
            self::assertSame(2, $this->repository->get($line->id())->version());
        }

        $fresh = $secondRepository->get($line->id());
        if ($adjustmentWins) {
            $fresh->updateSystemAmount(Money::USD('105000'), $this->now());
        } else {
            $fresh->addManualAdjustment($this->adjustment('-4555'));
        }
        $secondRepository->save($fresh);

        self::assertSame($adjustmentWins ? '95445' : '100445', $this->repository->get($line->id())->currentAmount()->getAmount());
    }

    public static function racingWrites(): iterable
    {
        yield 'manual adjustment wins' => [true];
        yield 'system update wins' => [false];
    }

    public function testAnInsertionFailureRollsBackStateAndAdjustmentsAndAllowsRetry(): void
    {
        $line = $this->line();
        $this->repository->save($line);
        $line->addManualAdjustment($this->adjustment('10'));
        $line->addManualAdjustment($this->adjustment('20'));
        $this->connection->exec("CREATE UNIQUE INDEX fail_second_adjustment ON earning_line_adjustments (earning_line_id) WHERE sequence > 1");

        try {
            $this->repository->save($line);
            self::fail('Expected the second insert to fail.');
        } catch (PDOException) {
            self::assertSame(1, $this->repository->get($line->id())->version());
            self::assertSame('100000', $this->repository->get($line->id())->currentAmount()->getAmount());
            self::assertCount(2, $line->pendingAdjustments());
        }

        $this->connection->exec('DROP INDEX fail_second_adjustment');
        $this->repository->save($line);
        self::assertSame('100030', $this->repository->get($line->id())->currentAmount()->getAmount());
        self::assertSame([], $line->pendingAdjustments());
    }

    public function testSavingLaterAdjustmentsPreservesEveryPreviouslySavedRecord(): void
    {
        $line = $this->line();
        $line->addManualAdjustment($this->adjustment('10'));
        $this->repository->save($line);
        $before = $this->connection->query('SELECT * FROM earning_line_adjustments ORDER BY sequence')->fetchAll(PDO::FETCH_ASSOC);
        $line = $this->repository->get($line->id());
        $line->addManualAdjustment($this->adjustment('-10'));
        $this->repository->save($line);
        $after = $this->connection->query('SELECT * FROM earning_line_adjustments ORDER BY sequence')->fetchAll(PDO::FETCH_ASSOC);
        self::assertSame($before, array_slice($after, 0, 2));
        self::assertCount(3, $after);
        self::assertSame('100000', $this->repository->get($line->id())->currentAmount()->getAmount());
    }

    public function testCreatingTheSameLineTwiceIsAConflict(): void
    {
        $line = $this->line();
        $other = EarningLine::create($line->id(), Money::USD('5'), $this->now());
        $this->repository->save($line);
        $this->expectException(ConcurrentStreamWrite::class);
        $this->repository->save($other);
    }

    public function testUnknownLineIsReportedExplicitly(): void
    {
        $this->expectException(EarningLineNotFound::class);
        $this->repository->get(new EarningLineId(Uuid::uuid4()->toString()));
    }

    public function testSavingAnUnchangedLineDoesNotAppendDuplicateAdjustments(): void
    {
        $line = $this->line();
        $this->repository->save($line);
        $this->repository->save($line);
        self::assertSame(1, $this->repository->get($line->id())->version());
    }

    public function testDifferentLinesHaveIndependentHistories(): void
    {
        $first = $this->line();
        $second = $this->line();
        $first->addManualAdjustment($this->adjustment('10'));
        $this->repository->save($first);
        $this->repository->save($second);

        self::assertSame('100010', $this->repository->get($first->id())->currentAmount()->getAmount());
        self::assertSame('100000', $this->repository->get($second->id())->currentAmount()->getAmount());
        self::assertCount(1, $this->repository->get($second->id())->adjustments());
    }

    public function testCompensationRemainsFrozenAfterLoadingSavedState(): void
    {
        $line = $this->line();
        $line->addManualAdjustment($this->adjustment('-20'));
        $line->addManualAdjustment($this->adjustment('20'));
        $this->repository->save($line);
        $restored = $this->repository->get($line->id());
        $restored->updateSystemAmount(Money::USD('200000'), $this->now());
        $this->repository->save($restored);
        self::assertTrue($restored->isManuallyAdjusted());
        self::assertSame('100000', $restored->currentAmount()->getAmount());
        self::assertCount(3, $this->repository->get($line->id())->adjustments());
        self::assertSame([], $restored->pendingAdjustments());
    }

    public function testFailureToInsertInitialAdjustmentAlsoRollsBackLineCreation(): void
    {
        $line = $this->line();
        $this->connection->exec("ALTER TABLE earning_line_adjustments RENAME TO hidden_adjustments");
        try {
            $this->repository->save($line);
            self::fail('Initial insert should fail.');
        } catch (PDOException) {
            self::assertSame(0, (int) $this->connection->query('SELECT COUNT(*) FROM earning_lines')->fetchColumn());
            self::assertCount(1, $line->pendingAdjustments());
            self::assertSame(0, $line->persistedVersion());
        }
        $this->connection->exec('ALTER TABLE hidden_adjustments RENAME TO earning_line_adjustments');
        $this->repository->save($line);
        $restored = $this->repository->get($line->id());
        self::assertTrue($restored->adjustments()[0]->type->isInitial());
        self::assertSame('100000', $restored->adjustments()[0]->amount->getAmount());
        self::assertSame([], $restored->pendingAdjustments());
    }

    public function testStorageUsesCompactTypesAndNoTriggers(): void
    {
        $line = $this->line();
        $line->updateSystemAmount(Money::USD('105000'), $this->now());
        $line->addManualAdjustment($this->adjustment('-4555'));
        $this->repository->save($line);
        $state = $this->connection->query('SELECT typeof(id) AS id_type, length(id) AS id_bytes, typeof(current_amount) AS amount_type, current_amount, typeof(created_at) AS time_type FROM earning_lines')->fetch(PDO::FETCH_ASSOC);
        self::assertSame('blob', $state['id_type']);
        self::assertSame(16, $state['id_bytes']);
        self::assertSame('integer', $state['amount_type']);
        self::assertSame(100445, $state['current_amount']);
        self::assertSame('integer', $state['time_type']);
        $rows = $this->connection->query('SELECT type, amount, typeof(earning_line_id) AS line_id_type, length(author_id) AS author_bytes, typeof(recorded_at) AS time_type FROM earning_line_adjustments ORDER BY sequence')->fetchAll(PDO::FETCH_ASSOC);
        self::assertSame([0, 1, 2], array_column($rows, 'type'));
        self::assertSame([100000, 5000, -4555], array_column($rows, 'amount'));
        self::assertSame('blob', $rows[2]['line_id_type']);
        self::assertSame(16, $rows[2]['author_bytes']);
        self::assertSame('integer', $rows[2]['time_type']);
        self::assertSame(0, (int) $this->connection->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'trigger'")->fetchColumn());
    }

    #[DataProvider('integerBoundaries')]
    public function testSignedIntegerBoundariesRoundTripExactly(string $amount): void
    {
        $line = EarningLine::create(new EarningLineId(Uuid::uuid4()->toString()), Money::USD($amount), $this->now());
        $this->repository->save($line);
        $restored = $this->repository->get($line->id());
        self::assertSame($amount, $restored->currentAmount()->getAmount());
        self::assertSame($amount, $restored->adjustments()[0]->amount->getAmount());
    }

    public static function integerBoundaries(): iterable
    {
        yield 'minimum' => ['-9223372036854775808'];
        yield 'maximum' => ['9223372036854775807'];
        yield 'zero' => ['0'];
    }

    #[DataProvider('outsideIntegerRange')]
    public function testOutOfRangeAmountsAreRejectedWithoutSavingAnything(string $amount): void
    {
        $line = EarningLine::create(new EarningLineId(Uuid::uuid4()->toString()), Money::USD($amount), $this->now());
        try {
            $this->repository->save($line);
            self::fail('An out-of-range amount was saved.');
        } catch (OverflowException) {
            self::assertSame(0, (int) $this->connection->query('SELECT COUNT(*) FROM earning_lines')->fetchColumn());
            self::assertSame(0, (int) $this->connection->query('SELECT COUNT(*) FROM earning_line_adjustments')->fetchColumn());
            self::assertCount(1, $line->pendingAdjustments());
        }
    }

    public static function outsideIntegerRange(): iterable
    {
        yield 'above maximum' => ['9223372036854775808'];
        yield 'below minimum' => ['-9223372036854775809'];
    }

    public function testOutOfRangeDeltaRollsBackEvenWhenTheNewStateFits(): void
    {
        $line = EarningLine::create(new EarningLineId(Uuid::uuid4()->toString()), Money::USD('-9223372036854775808'), $this->now());
        $this->repository->save($line);
        $line->updateSystemAmount(Money::USD('9223372036854775807'), $this->now());
        try {
            $this->repository->save($line);
            self::fail('An out-of-range delta was saved.');
        } catch (OverflowException) {
            $restored = $this->repository->get($line->id());
            self::assertSame('-9223372036854775808', $restored->currentAmount()->getAmount());
            self::assertCount(1, $restored->adjustments());
            self::assertCount(1, $line->pendingAdjustments());
        }
    }

    public function testPreEpochTimestampsKeepTheirMicroseconds(): void
    {
        $time = CarbonImmutable::parse('1969-12-31T23:59:59.123456Z');
        $line = EarningLine::create(new EarningLineId(Uuid::uuid4()->toString()), Money::USD('100'), $time);
        $this->repository->save($line);
        $restored = $this->repository->get($line->id());
        self::assertSame('1969-12-31T23:59:59.123456+00:00', $restored->createdAt()->format('Y-m-d\TH:i:s.uP'));
        self::assertEquals($time, $restored->adjustments()[0]->recordedAt);
    }

    private function line(): EarningLine
    {
        return EarningLine::create(new EarningLineId(Uuid::uuid4()->toString()), Money::USD('100000'), $this->now());
    }

    private function adjustment(string $amount): EarningLineAdjustment
    {
        return new EarningLineAdjustment(
            new EarningLineAdjustmentId(Uuid::uuid4()->toString()),
            Money::USD($amount),
            'Коригування: approved correction',
            new AdjustmentAuthorId(Uuid::uuid4()->toString()),
            $this->now(),
        );
    }

    private function now(): CarbonImmutable
    {
        return CarbonImmutable::parse('2026-09-15T10:00:00.123456Z');
    }
}
