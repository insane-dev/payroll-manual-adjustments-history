<?php

declare(strict_types=1);

namespace App\Tests\Integration\Earning\Infrastructure\Persist\Write;

use App\Earning\Application\Exception\ConcurrentEarningLineWrite;
use App\Earning\Application\Exception\EarningLineNotFound;
use App\Earning\Domain\Entity\EarningLine;
use App\Earning\Domain\Entity\EarningLineAdjustment;
use App\Earning\Domain\Repository\EarningLineRepository;
use App\Earning\Domain\Value\AdjustmentAuthorId;
use App\Earning\Domain\Value\EarningLineAdjustmentId;
use App\Earning\Domain\Value\EarningLineId;
use App\Earning\Infrastructure\Persist\Write\MysqlEarningLineRepository;
use App\Earning\Infrastructure\Persist\Write\MysqlSchema;
use App\Tests\Shared\Infrastructure\Persist\MysqlTestDatabase;
use Carbon\CarbonImmutable;
use Money\Money;
use OverflowException;
use PDO;
use PDOException;
use PDOStatement;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
use RuntimeException;

final class MysqlEarningLineRepositoryTest extends TestCase
{
    private MysqlTestDatabase $database;
    private PDO $connection;
    private EarningLineRepository $earningLines;

    protected function setUp(): void
    {
        $this->database = new MysqlTestDatabase();
        (new MysqlSchema())->initialize($this->database->connection());
        $this->connection = $this->database->connection();
        $this->earningLines = new MysqlEarningLineRepository($this->connection);
    }

    protected function tearDown(): void
    {
        unset($this->earningLines, $this->connection);
        $this->database->drop();
    }

    public function testStateAndAdjustmentsSurviveReopeningTheDatabaseWithExactAmountsAndAuditMetadata(): void
    {
        $line = $this->line();
        $line->updateSystemAmount(Money::USD('9223372036854775806'), $this->now());
        $adjustment = $this->adjustment('1');
        $line->addManualAdjustment($adjustment);
        $this->earningLines->save($line);
        self::assertSame([], $line->pendingAdjustments());

        $reopened = new MysqlEarningLineRepository($this->database->connection());
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
        $this->earningLines->save($line);
        $first = $this->earningLines->get($line->id());
        $secondEarningLines = new MysqlEarningLineRepository($this->database->connection());
        $stale = $secondEarningLines->get($line->id());

        if ($adjustmentWins) {
            $first->addManualAdjustment($this->adjustment('-4555'));
            $stale->updateSystemAmount(Money::USD('105000'), $this->now());
        } else {
            $first->updateSystemAmount(Money::USD('105000'), $this->now());
            $stale->addManualAdjustment($this->adjustment('-4555'));
        }
        $this->earningLines->save($first);

        try {
            $secondEarningLines->save($stale);
            self::fail('A stale writer was accepted.');
        } catch (ConcurrentEarningLineWrite) {
            self::assertCount(1, $stale->pendingAdjustments());
            self::assertSame(2, $this->earningLines->get($line->id())->version());
        }

        $fresh = $secondEarningLines->get($line->id());
        if ($adjustmentWins) {
            $fresh->updateSystemAmount(Money::USD('105000'), $this->now());
        } else {
            $fresh->addManualAdjustment($this->adjustment('-4555'));
        }
        $secondEarningLines->save($fresh);

        self::assertSame($adjustmentWins ? '95445' : '100445', $this->earningLines->get($line->id())->currentAmount()->getAmount());
    }

    /** @return iterable<string, array{bool}> */
    public static function racingWrites(): iterable
    {
        yield 'manual adjustment wins' => [true];
        yield 'system update wins' => [false];
    }

    public function testAnInsertionFailureRollsBackStateAndAdjustmentsAndAllowsRetry(): void
    {
        $line = $this->line();
        $this->earningLines->save($line);
        $line->addManualAdjustment($this->adjustment('10'));
        $line->addManualAdjustment($this->adjustment('20'));
        $this->connection->exec("ALTER TABLE earning_line_adjustments ADD CONSTRAINT fail_second_adjustment CHECK (sequence < 3)");

        try {
            $this->earningLines->save($line);
            self::fail('Expected the second insert to fail.');
        } catch (PDOException) {
            self::assertSame(1, $this->earningLines->get($line->id())->version());
            self::assertSame('100000', $this->earningLines->get($line->id())->currentAmount()->getAmount());
            self::assertCount(2, $line->pendingAdjustments());
        }

        $this->connection->exec('ALTER TABLE earning_line_adjustments DROP CHECK fail_second_adjustment');
        $this->earningLines->save($line);
        self::assertSame('100030', $this->earningLines->get($line->id())->currentAmount()->getAmount());
        self::assertSame([], $line->pendingAdjustments());
    }

    public function testSavingLaterAdjustmentsPreservesEveryPreviouslySavedRecord(): void
    {
        $line = $this->line();
        $line->addManualAdjustment($this->adjustment('10'));
        $this->earningLines->save($line);
        $before = $this->query('SELECT * FROM earning_line_adjustments ORDER BY sequence')->fetchAll(PDO::FETCH_ASSOC);
        $line = $this->earningLines->get($line->id());
        $line->addManualAdjustment($this->adjustment('-10'));
        $this->earningLines->save($line);
        $after = $this->query('SELECT * FROM earning_line_adjustments ORDER BY sequence')->fetchAll(PDO::FETCH_ASSOC);
        self::assertSame($before, array_slice($after, 0, 2));
        self::assertCount(3, $after);
        self::assertSame('100000', $this->earningLines->get($line->id())->currentAmount()->getAmount());
    }

    public function testCreatingTheSameLineTwiceIsAConflict(): void
    {
        $line = $this->line();
        $other = EarningLine::create($line->id(), Money::USD('5'), $this->now());
        $this->earningLines->save($line);
        $this->expectException(ConcurrentEarningLineWrite::class);
        $this->earningLines->save($other);
    }

    public function testUnknownLineIsReportedExplicitly(): void
    {
        $this->expectException(EarningLineNotFound::class);
        $this->earningLines->get(new EarningLineId(Uuid::uuid7()->toString()));
    }

    public function testSavingAnUnchangedLineDoesNotAppendDuplicateAdjustments(): void
    {
        $line = $this->line();
        $this->earningLines->save($line);
        $this->earningLines->save($line);
        self::assertSame(1, $this->earningLines->get($line->id())->version());
    }

    public function testDifferentLinesHaveIndependentHistories(): void
    {
        $first = $this->line();
        $second = $this->line();
        $first->addManualAdjustment($this->adjustment('10'));
        $this->earningLines->save($first);
        $this->earningLines->save($second);

        self::assertSame('100010', $this->earningLines->get($first->id())->currentAmount()->getAmount());
        self::assertSame('100000', $this->earningLines->get($second->id())->currentAmount()->getAmount());
        self::assertCount(1, $this->earningLines->get($second->id())->adjustments());
    }

    public function testCompensationRemainsFrozenAfterLoadingSavedState(): void
    {
        $line = $this->line();
        $line->addManualAdjustment($this->adjustment('-20'));
        $line->addManualAdjustment($this->adjustment('20'));
        $this->earningLines->save($line);
        $restored = $this->earningLines->get($line->id());
        $restored->updateSystemAmount(Money::USD('200000'), $this->now());
        $this->earningLines->save($restored);
        self::assertTrue($restored->isManuallyAdjusted());
        self::assertSame('100000', $restored->currentAmount()->getAmount());
        self::assertCount(3, $this->earningLines->get($line->id())->adjustments());
        self::assertSame([], $restored->pendingAdjustments());
    }

    public function testFailureToInsertInitialAdjustmentAlsoRollsBackLineCreation(): void
    {
        $line = $this->line();
        $this->connection->exec("ALTER TABLE earning_line_adjustments RENAME TO hidden_adjustments");
        try {
            $this->earningLines->save($line);
            self::fail('Initial insert should fail.');
        } catch (PDOException) {
            self::assertSame(0, (int) $this->query('SELECT COUNT(*) FROM earning_lines')->fetchColumn());
            self::assertCount(1, $line->pendingAdjustments());
            self::assertSame(0, $line->persistedVersion());
        }
        $this->connection->exec('ALTER TABLE hidden_adjustments RENAME TO earning_line_adjustments');
        $this->earningLines->save($line);
        $restored = $this->earningLines->get($line->id());
        self::assertTrue($restored->adjustments()[0]->type->isInitial());
        self::assertSame('100000', $restored->adjustments()[0]->amount->getAmount());
        self::assertSame([], $restored->pendingAdjustments());
    }

    public function testStorageUsesCompactTypesAndNoTriggers(): void
    {
        $line = $this->line();
        $line->updateSystemAmount(Money::USD('105000'), $this->now());
        $line->addManualAdjustment($this->adjustment('-4555'));
        $this->earningLines->save($line);
        $state = $this->query('SELECT OCTET_LENGTH(id) AS id_bytes, current_amount FROM earning_lines')->fetch(PDO::FETCH_ASSOC);
        self::assertSame(16, $state['id_bytes']);
        self::assertSame(100445, $state['current_amount']);
        $columns = $this->query("SELECT COLUMN_NAME, COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'earning_lines'")->fetchAll(PDO::FETCH_KEY_PAIR);
        self::assertSame('binary(16)', $columns['id']);
        self::assertSame('bigint', $columns['current_amount']);
        self::assertSame('tinyint unsigned', $columns['manually_adjusted']);
        self::assertSame('int unsigned', $columns['version']);
        self::assertSame('bigint', $columns['created_at']);
        $adjustmentColumns = $this->query("SELECT COLUMN_NAME, COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'earning_line_adjustments'")->fetchAll(PDO::FETCH_KEY_PAIR);
        self::assertSame("enum('initial','system','manual')", $adjustmentColumns['type']);
        $rows = $this->query('SELECT type, amount, OCTET_LENGTH(author_id) AS author_bytes FROM earning_line_adjustments ORDER BY sequence')->fetchAll(PDO::FETCH_ASSOC);
        self::assertSame(['initial', 'system', 'manual'], array_column($rows, 'type'));
        self::assertSame([100000, 5000, -4555], array_column($rows, 'amount'));
        self::assertSame(16, $rows[2]['author_bytes']);
        self::assertSame(0, (int) $this->query('SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE()')->fetchColumn());
    }

    public function testHistoryUsesTheClusteredIndexWithoutSorting(): void
    {
        $line = $this->line();
        $line->updateSystemAmount(Money::USD('105000'), $this->now());
        $line->addManualAdjustment($this->adjustment('-4555'));
        $this->earningLines->save($line);
        $indexes = $this->query("SELECT INDEX_NAME, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS columns_in_order FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'earning_line_adjustments' GROUP BY INDEX_NAME")->fetchAll(PDO::FETCH_KEY_PAIR);
        self::assertSame([
            'PRIMARY' => 'earning_line_id,sequence',
            'uq_line_adjustment_id' => 'earning_line_id,id',
        ], $indexes);

        $hexId = bin2hex(Uuid::fromString($line->id()->value)->getBytes());
        $plan = $this->query("EXPLAIN SELECT * FROM earning_line_adjustments WHERE earning_line_id = UNHEX('$hexId') ORDER BY sequence")->fetch(PDO::FETCH_ASSOC);
        self::assertSame('PRIMARY', $plan['key']);
        self::assertStringNotContainsString('filesort', $plan['Extra'] ?? '');
    }

    public function testDatabaseRejectsAmountsOutsideSignedBigintRange(): void
    {
        $line = $this->line();
        $this->earningLines->save($line);
        $this->expectException(PDOException::class);
        $this->query("UPDATE earning_lines SET current_amount = '9223372036854775808'");
    }

    /** @return iterable<string, array{string}> */
    public static function invalidAdjustmentTypes(): iterable
    {
        yield 'unknown value' => ['unknown'];
        yield 'empty value' => [''];
        yield 'wrong case' => ['MANUAL'];
    }

    #[DataProvider('invalidAdjustmentTypes')]
    public function testDatabaseRejectsInvalidAdjustmentTypes(string $type): void
    {
        $line = $this->line();
        $line->addManualAdjustment($this->adjustment('10'));
        $this->earningLines->save($line);
        $statement = $this->connection->prepare('UPDATE earning_line_adjustments SET type = :type WHERE sequence = 2');
        $this->expectException(PDOException::class);
        $statement->execute(['type' => $type]);
    }

    public function testAdjustmentIdentityRemainsScopedToItsLine(): void
    {
        $first = $this->line();
        $second = $this->line();
        $adjustment = $this->adjustment('10');
        $first->addManualAdjustment($adjustment);
        $second->addManualAdjustment($adjustment);
        $this->earningLines->save($first);
        $this->earningLines->save($second);
        self::assertSame('100010', $this->earningLines->get($first->id())->currentAmount()->getAmount());
        self::assertSame('100010', $this->earningLines->get($second->id())->currentAmount()->getAmount());
    }

    /** @param numeric-string $amount */
    #[DataProvider('integerBoundaries')]
    public function testSignedIntegerBoundariesRoundTripExactly(string $amount): void
    {
        $line = EarningLine::create(new EarningLineId(Uuid::uuid7()->toString()), Money::USD($amount), $this->now());
        $this->earningLines->save($line);
        $restored = $this->earningLines->get($line->id());
        self::assertSame($amount, $restored->currentAmount()->getAmount());
        self::assertSame($amount, $restored->adjustments()[0]->amount->getAmount());
    }

    /** @return iterable<string, array{numeric-string}> */
    public static function integerBoundaries(): iterable
    {
        yield 'minimum' => ['-9223372036854775808'];
        yield 'maximum' => ['9223372036854775807'];
        yield 'zero' => ['0'];
    }

    /** @param numeric-string $amount */
    #[DataProvider('outsideIntegerRange')]
    public function testOutOfRangeAmountsAreRejectedWithoutSavingAnything(string $amount): void
    {
        $line = EarningLine::create(new EarningLineId(Uuid::uuid7()->toString()), Money::USD($amount), $this->now());
        try {
            $this->earningLines->save($line);
            self::fail('An out-of-range amount was saved.');
        } catch (OverflowException) {
            self::assertSame(0, (int) $this->query('SELECT COUNT(*) FROM earning_lines')->fetchColumn());
            self::assertSame(0, (int) $this->query('SELECT COUNT(*) FROM earning_line_adjustments')->fetchColumn());
            self::assertCount(1, $line->pendingAdjustments());
        }
    }

    /** @return iterable<string, array{numeric-string}> */
    public static function outsideIntegerRange(): iterable
    {
        yield 'above maximum' => ['9223372036854775808'];
        yield 'below minimum' => ['-9223372036854775809'];
    }

    public function testOutOfRangeDeltaRollsBackEvenWhenTheNewStateFits(): void
    {
        $line = EarningLine::create(new EarningLineId(Uuid::uuid7()->toString()), Money::USD('-9223372036854775808'), $this->now());
        $this->earningLines->save($line);
        $line->updateSystemAmount(Money::USD('9223372036854775807'), $this->now());
        try {
            $this->earningLines->save($line);
            self::fail('An out-of-range delta was saved.');
        } catch (OverflowException) {
            $restored = $this->earningLines->get($line->id());
            self::assertSame('-9223372036854775808', $restored->currentAmount()->getAmount());
            self::assertCount(1, $restored->adjustments());
            self::assertCount(1, $line->pendingAdjustments());
        }
    }

    public function testPreEpochTimestampsKeepTheirMicroseconds(): void
    {
        $time = CarbonImmutable::parse('1969-12-31T23:59:59.123456Z');
        $line = EarningLine::create(new EarningLineId(Uuid::uuid7()->toString()), Money::USD('100'), $time);
        $this->earningLines->save($line);
        $restored = $this->earningLines->get($line->id());
        self::assertSame('1969-12-31T23:59:59.123456+00:00', $restored->createdAt()->format('Y-m-d\TH:i:s.uP'));
        self::assertEquals($time, $restored->adjustments()[0]->recordedAt);
    }

    private function line(): EarningLine
    {
        return EarningLine::create(new EarningLineId(Uuid::uuid7()->toString()), Money::USD('100000'), $this->now());
    }

    /** @param numeric-string $amount */
    private function adjustment(string $amount): EarningLineAdjustment
    {
        return new EarningLineAdjustment(
            new EarningLineAdjustmentId(Uuid::uuid7()->toString()),
            Money::USD($amount),
            'Коригування: approved correction 💰',
            new AdjustmentAuthorId(Uuid::uuid7()->toString()),
            $this->now(),
        );
    }

    private function query(string $sql): PDOStatement
    {
        $statement = $this->connection->query($sql);
        if ($statement === false) {
            throw new RuntimeException('Cannot execute test query.');
        }

        return $statement;
    }

    private function now(): CarbonImmutable
    {
        return CarbonImmutable::parse('2026-09-15T10:00:00.123456Z');
    }
}
