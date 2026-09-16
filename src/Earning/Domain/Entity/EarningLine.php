<?php

declare(strict_types=1);

namespace App\Earning\Domain\Entity;

use App\Earning\Domain\Value\EarningLineAdjustmentId;
use App\Earning\Domain\Value\EarningLineAdjustmentType;
use App\Earning\Domain\Value\EarningLineId;
use Carbon\CarbonImmutable;
use DomainException;
use Money\Money;
use Ramsey\Uuid\Uuid;

final class EarningLine
{
    /** @var array<string, EarningLineAdjustment> */
    private array $adjustments = [];
    /** @var list<EarningLineAdjustment> */
    private array $pendingAdjustments = [];
    private int $persistedVersion = 0;

    private function __construct(
        private EarningLineId $id,
        private Money $initialAmount,
        private Money $systemAmount,
        private Money $currentAmount,
        private bool $manuallyAdjusted,
        private int $version,
        private CarbonImmutable $createdAt,
    ) {}

    public static function create(EarningLineId $id, Money $systemAmount, CarbonImmutable $recordedAt): self
    {
        $zero = new Money('0', $systemAmount->getCurrency());
        $line = new self($id, $systemAmount, $zero, $zero, false, 0, $recordedAt->utc());
        $line->appendAdjustment(new EarningLineAdjustment(
            new EarningLineAdjustmentId(Uuid::uuid7()->toString()),
            $systemAmount,
            null,
            null,
            $recordedAt,
            EarningLineAdjustmentType::INITIAL,
        ));

        return $line;
    }

    /** @param list<EarningLineAdjustment> $adjustments */
    public static function restore(
        EarningLineId $id,
        Money $initialAmount,
        Money $systemAmount,
        Money $currentAmount,
        bool $manuallyAdjusted,
        int $version,
        CarbonImmutable $createdAt,
        array $adjustments,
    ): self {
        $line = new self($id, $initialAmount, $systemAmount, $currentAmount, $manuallyAdjusted, $version, $createdAt->utc());
        foreach ($adjustments as $adjustment) {
            $line->adjustments[$adjustment->id->value] = $adjustment;
        }
        $line->persistedVersion = $version;

        return $line;
    }

    public function updateSystemAmount(Money $systemAmount, CarbonImmutable $recordedAt): void
    {
        if ($this->manuallyAdjusted) {
            return;
        }
        $this->assertSameCurrency($systemAmount);
        if ($this->systemAmount->equals($systemAmount)) {
            return;
        }

        $this->appendAdjustment(new EarningLineAdjustment(
            new EarningLineAdjustmentId(Uuid::uuid7()->toString()),
            $systemAmount->subtract($this->systemAmount),
            null,
            null,
            $recordedAt,
            EarningLineAdjustmentType::SYSTEM,
        ));
    }

    public function addManualAdjustment(EarningLineAdjustment $adjustment): void
    {
        if (!$adjustment->type->isManual()) {
            throw new DomainException('Use Update System Amount for automatic recalculations.');
        }
        $this->appendAdjustment($adjustment);
    }

    private function appendAdjustment(EarningLineAdjustment $adjustment): void
    {
        $this->assertSameCurrency($adjustment->amount);
        if (isset($this->adjustments[$adjustment->id->value])) {
            throw new DomainException('This Earning Line Adjustment Id already exists.');
        }
        $this->currentAmount = $this->currentAmount->add($adjustment->amount);
        if ($adjustment->type->isManual()) {
            $this->manuallyAdjusted = true;
        } else {
            $this->systemAmount = $this->systemAmount->add($adjustment->amount);
        }
        $this->adjustments[$adjustment->id->value] = $adjustment;
        $this->pendingAdjustments[] = $adjustment;
        ++$this->version;
    }

    public function id(): EarningLineId
    {
        return $this->id;
    }

    public function initialAmount(): Money
    {
        return $this->initialAmount;
    }

    public function systemAmount(): Money
    {
        return $this->systemAmount;
    }

    public function currentAmount(): Money
    {
        return $this->currentAmount;
    }

    public function isManuallyAdjusted(): bool
    {
        return $this->manuallyAdjusted;
    }

    public function version(): int
    {
        return $this->version;
    }

    public function persistedVersion(): int
    {
        return $this->persistedVersion;
    }

    public function createdAt(): CarbonImmutable
    {
        return $this->createdAt;
    }

    /** @return list<EarningLineAdjustment> */
    public function adjustments(): array
    {
        return array_values($this->adjustments);
    }

    /** @return list<EarningLineAdjustment> */
    public function pendingAdjustments(): array
    {
        return $this->pendingAdjustments;
    }

    public function markPersisted(): void
    {
        $this->persistedVersion = $this->version;
        $this->pendingAdjustments = [];
    }

    private function assertSameCurrency(Money $amount): void
    {
        if (!$this->systemAmount->isSameCurrency($amount)) {
            throw new DomainException('Amounts must use the Earning Line currency.');
        }
    }
}
