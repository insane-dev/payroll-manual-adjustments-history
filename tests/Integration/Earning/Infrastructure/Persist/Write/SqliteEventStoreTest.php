<?php

declare(strict_types=1);

namespace App\Tests\Integration\Earning\Infrastructure\Persist\Write;

use App\Earning\Application\Exception\ConcurrentStreamWrite;
use App\Earning\Application\Exception\EarningLineNotFound;
use App\Earning\Domain\Entity\EarningLine;
use App\Earning\Domain\Entity\ManualAdjustment;
use App\Earning\Domain\Repository\EarningLineRepository;
use App\Earning\Domain\Value\AdjustmentAuthorId;
use App\Earning\Domain\Value\EarningLineId;
use App\Earning\Domain\Value\ManualAdjustmentId;
use App\Earning\Infrastructure\Mapper\EventSerializer;
use App\Earning\Infrastructure\Persist\Write\EventSourcedEarningLineRepository;
use App\Earning\Infrastructure\Persist\Write\SqliteEventStore;
use Carbon\CarbonImmutable;
use PDO;
use PDOException;
use Money\Money;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
use UnexpectedValueException;

final class SqliteEventStoreTest extends TestCase
{
    private string $database;
    private PDO $connection;
    private SqliteEventStore $store;
    private EarningLineRepository $repository;

    protected function setUp(): void
    {
        $this->database = tempnam(sys_get_temp_dir(), 'alcor-');
        $this->connection = new PDO('sqlite:' . $this->database);
        $this->store = new SqliteEventStore($this->connection, new EventSerializer());
        $this->repository = new EventSourcedEarningLineRepository($this->store);
    }

    protected function tearDown(): void
    {
        unset($this->repository, $this->store, $this->connection);
        unlink($this->database);
    }

    public function testEventsSurviveReopeningTheDatabaseWithExactAmountsAndAuditMetadata(): void
    {
        $line = $this->line();
        $line->updateSystemAmount(Money::USD('999999999999999999999999'), $this->now());
        $adjustment = $this->adjustment('1');
        $line->addManualAdjustment($adjustment);
        $this->repository->save($line);
        self::assertSame([], $line->recordedEvents());

        $reopened = new EventSourcedEarningLineRepository(new SqliteEventStore(new PDO('sqlite:' . $this->database), new EventSerializer()));
        $restored = $reopened->get($line->id());

        self::assertSame($line->id()->value, $restored->id()->value);
        self::assertSame('999999999999999999999999', $restored->systemAmount()->getAmount());
        self::assertSame('1000000000000000000000000', $restored->currentAmount()->getAmount());
        self::assertEquals([$adjustment], $restored->adjustments());
        self::assertSame('2026-09-15T10:00:00.123456+00:00', $restored->adjustments()[0]->recordedAt->format('Y-m-d\TH:i:s.uP'));
        self::assertSame(3, $restored->version());
        self::assertSame([], $restored->recordedEvents());
    }

    #[DataProvider('racingWrites')]
    public function testAStaleWriterCannotOverwriteAnAcceptedChange(bool $adjustmentWins): void
    {
        $line = $this->line();
        $this->repository->save($line);
        $first = $this->repository->get($line->id());
        $secondRepository = new EventSourcedEarningLineRepository(new SqliteEventStore(new PDO('sqlite:' . $this->database), new EventSerializer()));
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
            self::assertCount(1, $stale->recordedEvents());
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

    public function testAnInsertionFailureRollsBackTheEntireBatchAndLeavesEventsAvailableForRetry(): void
    {
        $line = $this->line();
        $this->repository->save($line);
        $line->addManualAdjustment($this->adjustment('10'));
        $line->addManualAdjustment($this->adjustment('20'));
        $this->connection->exec("CREATE TRIGGER fail_third_event BEFORE INSERT ON earning_line_events WHEN NEW.version = 3 BEGIN SELECT RAISE(ABORT, 'Simulated storage failure'); END");

        try {
            $this->repository->save($line);
            self::fail('Expected the second insert to fail.');
        } catch (PDOException) {
            self::assertSame(1, $this->repository->get($line->id())->version());
            self::assertSame('100000', $this->repository->get($line->id())->currentAmount()->getAmount());
            self::assertCount(2, $line->recordedEvents());
        }

        $this->connection->exec('DROP TRIGGER fail_third_event');
        $this->repository->save($line);
        self::assertSame('100030', $this->repository->get($line->id())->currentAmount()->getAmount());
        self::assertSame([], $line->recordedEvents());
    }

    #[DataProvider('forbiddenMutations')]
    public function testSavedEventsCannotBeUpdatedOrDeleted(string $sql): void
    {
        $line = $this->line();
        $line->addManualAdjustment($this->adjustment('10'));
        $this->repository->save($line);

        try {
            $this->connection->exec($sql);
            self::fail('Saved events were mutated.');
        } catch (PDOException) {
            self::assertSame('100010', $this->repository->get($line->id())->currentAmount()->getAmount());
            self::assertCount(2, $this->store->load($line->id()));
        }
    }

    public static function forbiddenMutations(): iterable
    {
        yield 'update' => ["UPDATE earning_line_events SET payload = '{}' WHERE version = 2"];
        yield 'delete' => ['DELETE FROM earning_line_events WHERE version = 2'];
        yield 'replace' => ["INSERT OR REPLACE INTO earning_line_events (stream_id, version, event_type, payload) SELECT stream_id, version, event_type, '{}' FROM earning_line_events WHERE version = 2"];
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

    public function testSavingAnUnchangedLineDoesNotAppendDuplicateEvents(): void
    {
        $line = $this->line();
        $this->repository->save($line);
        $this->repository->save($line);
        self::assertCount(1, $this->store->load($line->id()));
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
        self::assertSame([], $this->repository->get($second->id())->adjustments());
    }

    public function testUnknownEventTypesAreNotSilentlySkipped(): void
    {
        $this->expectException(UnexpectedValueException::class);
        (new EventSerializer())->decode('future-event.v2', '{}');
    }

    private function line(): EarningLine
    {
        return EarningLine::create(new EarningLineId(Uuid::uuid4()->toString()), Money::USD('100000'), $this->now());
    }

    private function adjustment(string $amount): ManualAdjustment
    {
        return new ManualAdjustment(
            new ManualAdjustmentId(Uuid::uuid4()->toString()),
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
