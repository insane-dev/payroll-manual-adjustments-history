<?php

declare(strict_types=1);

namespace App\Tests\Unit\Earning\Domain\Entity;

use App\Earning\Domain\Entity\EarningLine;
use App\Earning\Domain\Entity\EarningLineAdjustment;
use App\Earning\Domain\Value\AdjustmentAuthorId;
use App\Earning\Domain\Value\EarningLineAdjustmentId;
use App\Earning\Domain\Value\EarningLineAdjustmentType;
use App\Earning\Domain\Value\EarningLineId;
use Carbon\CarbonImmutable;
use DomainException;
use Money\Money;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

final class EarningLineTest extends TestCase
{
    public function testAssignmentScenarioPreservesEveryAdjustmentAndFreezesLastSystemAmount(): void
    {
        $line = $this->line();
        self::assertSame('100000', $line->currentAmount()->getAmount());

        $line->updateSystemAmount(Money::USD('105000'), $this->now());
        self::assertSame('105000', $line->currentAmount()->getAmount());

        $first = $this->adjustment('-4555', 'Employee declined dental benefit; reversing deduction');
        $line->addManualAdjustment($first);
        self::assertSame('100445', $line->currentAmount()->getAmount());

        $line->updateSystemAmount(Money::USD('900000'), $this->now());
        self::assertSame('100445', $line->currentAmount()->getAmount());

        foreach ([
            ['10010', 'Late correction: missed approved overtime bonus', '110455'],
            ['-10', 'Minor rounding adjustment', '110445'],
            ['-20', 'Second minor rounding adjustment', '110425'],
            ['20', 'Correcting mistake in adjustment #4', '110445'],
        ] as [$amount, $comment, $expected]) {
            $line->addManualAdjustment($this->adjustment($amount, $comment));
            self::assertSame($expected, $line->currentAmount()->getAmount());
        }

        self::assertSame('105000', $line->systemAmount()->getAmount());
        self::assertCount(7, $line->adjustments());
        self::assertSame($first, $line->adjustments()[2]);
        self::assertSame(['100000', '5000', '-4555', '10010', '-10', '-20', '20'], array_map(
            static fn(EarningLineAdjustment $adjustment): string => $adjustment->amount->getAmount(),
            $line->adjustments(),
        ));

    }

    public function testCompensationDoesNotRestoreAutomaticUpdates(): void
    {
        $line = $this->line();
        $line->addManualAdjustment($this->adjustment('-20'));
        $line->addManualAdjustment($this->adjustment('20'));
        $restored = $line;

        $restored->updateSystemAmount(Money::USD('200000'), $this->now());

        self::assertSame('100000', $restored->currentAmount()->getAmount());
        self::assertCount(3, $restored->adjustments());
        self::assertSame(3, $restored->version());
    }

    public function testRepeatedSystemAmountDoesNotProduceAnAdjustment(): void
    {
        $line = $this->line();
        $line->markPersisted();
        $line->updateSystemAmount(Money::USD('100000'), $this->now());

        self::assertSame([], $line->pendingAdjustments());
        self::assertSame(1, $line->version());
    }

    public function testDuplicateAdjustmentIdIsRejectedWithoutChangingHistory(): void
    {
        $line = $this->line();
        $adjustment = $this->adjustment('10');
        $line->addManualAdjustment($adjustment);

        try {
            $line->addManualAdjustment($adjustment);
            self::fail('A duplicate adjustment was accepted.');
        } catch (DomainException) {
            self::assertCount(2, $line->adjustments());
            self::assertSame('100010', $line->currentAmount()->getAmount());
            self::assertSame(2, $line->version());
        }
    }

    public function testAdjustmentCurrencyMustMatchTheLine(): void
    {
        $line = $this->line();
        $adjustment = new EarningLineAdjustment(
            new EarningLineAdjustmentId(Uuid::uuid7()->toString()),
            Money::EUR('10'),
            'Wrong currency',
            new AdjustmentAuthorId(Uuid::uuid7()->toString()),
            $this->now(),
        );

        try {
            $line->addManualAdjustment($adjustment);
            self::fail('A different currency was accepted.');
        } catch (DomainException) {
            self::assertSame('100000', $line->currentAmount()->getAmount());
            self::assertCount(1, $line->adjustments());
        }
    }

    public function testSystemUpdateCannotChangeTheCurrency(): void
    {
        $line = $this->line();
        $this->expectException(DomainException::class);
        $line->updateSystemAmount(Money::EUR('105000'), $this->now());
    }

    public function testAmountsBeyondNativeIntegerRangeRemainExact(): void
    {
        $line = EarningLine::create(
            new EarningLineId(Uuid::uuid7()->toString()),
            Money::USD('999999999999999999999999'),
            $this->now(),
        );
        $line->addManualAdjustment($this->adjustment('1'));

        self::assertSame('1000000000000000000000000', $line->currentAmount()->getAmount());
    }

    public function testNegativeCurrentAmountIsAllowed(): void
    {
        $line = $this->line();
        $line->addManualAdjustment($this->adjustment('-100001'));
        self::assertSame('-1', $line->currentAmount()->getAmount());
    }

    /** @param numeric-string $amount */
    #[DataProvider('invalidAdjustments')]
    public function testInvalidAdjustmentIsRejected(string $amount, string $comment): void
    {
        $this->expectException(DomainException::class);
        $this->adjustment($amount, $comment);
    }

    /** @return iterable<string, array{numeric-string, string}> */
    public static function invalidAdjustments(): iterable
    {
        yield 'zero' => ['0', 'No change'];
        yield 'empty comment' => ['1', ''];
        yield 'whitespace comment' => ['1', " \t\n"];
        yield 'unicode whitespace comment' => ['1', "\u{00A0}\u{2003}"];
    }

    public function testRecordedTimeIsNormalizedToUtcWithoutLosingPrecision(): void
    {
        $adjustment = new EarningLineAdjustment(
            new EarningLineAdjustmentId(Uuid::uuid7()->toString()),
            Money::USD('1'),
            'Keep the original comment verbatim. ',
            new AdjustmentAuthorId(Uuid::uuid7()->toString()),
            CarbonImmutable::parse('2026-09-15T12:34:56.123456+03:00'),
        );

        self::assertSame('2026-09-15T09:34:56.123456+00:00', $adjustment->recordedAt->format('Y-m-d\TH:i:s.uP'));
        self::assertSame('Keep the original comment verbatim. ', $adjustment->comment);
    }

    public function testSystemRecalculationsRecordSignedDeltasWithoutFreezingTheLine(): void
    {
        $line = $this->line();
        $line->updateSystemAmount(Money::USD('105000'), $this->now());
        $line->updateSystemAmount(Money::USD('99000'), $this->now());
        self::assertSame(['100000', '5000', '-6000'], array_map(
            static fn(EarningLineAdjustment $adjustment): string => $adjustment->amount->getAmount(),
            $line->adjustments(),
        ));
        self::assertSame(EarningLineAdjustmentType::SYSTEM, $line->adjustments()[1]->type);
        self::assertFalse($line->isManuallyAdjusted());
        self::assertSame('100000', $line->initialAmount()->getAmount());
        self::assertSame('99000', $line->systemAmount()->getAmount());
        self::assertSame('99000', $line->currentAmount()->getAmount());
    }

    public function testManualAdjustmentRequiresAnAuthor(): void
    {
        $this->expectException(DomainException::class);
        new EarningLineAdjustment(new EarningLineAdjustmentId(Uuid::uuid7()->toString()), Money::USD('1'), 'Correction', null, $this->now());
    }

    public function testCreationRecordsOneInitialAdjustmentIncludingZero(): void
    {
        $line = EarningLine::create(new EarningLineId(Uuid::uuid7()->toString()), Money::USD('0'), $this->now());
        self::assertCount(1, $line->adjustments());
        self::assertTrue($line->adjustments()[0]->type->isInitial());
        self::assertSame('0', $line->adjustments()[0]->amount->getAmount());
        self::assertSame('0', $line->currentAmount()->getAmount());
        self::assertFalse($line->isManuallyAdjusted());
        self::assertCount(1, $line->pendingAdjustments());
        $line->updateSystemAmount(Money::USD('5000'), $this->now());
        self::assertSame('5000', $line->currentAmount()->getAmount());
    }

    private function line(): EarningLine
    {
        return EarningLine::create(new EarningLineId(Uuid::uuid7()->toString()), Money::USD('100000'), $this->now());
    }

    /** @param numeric-string $amount */
    private function adjustment(string $amount, string $comment = 'Correction'): EarningLineAdjustment
    {
        return new EarningLineAdjustment(
            new EarningLineAdjustmentId(Uuid::uuid7()->toString()),
            Money::USD($amount),
            $comment,
            new AdjustmentAuthorId(Uuid::uuid7()->toString()),
            $this->now(),
        );
    }

    private function now(): CarbonImmutable
    {
        return CarbonImmutable::parse('2026-09-15T10:00:00.123456Z');
    }
}
