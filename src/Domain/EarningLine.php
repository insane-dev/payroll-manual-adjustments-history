<?php

declare(strict_types=1);

namespace Alcor\Payroll\Domain;

use Alcor\Payroll\Domain\Event\EarningLineCreated;
use Alcor\Payroll\Domain\Event\EarningLineEvent;
use Alcor\Payroll\Domain\Event\ManualAdjustmentAdded;
use Alcor\Payroll\Domain\Event\SystemAmountUpdated;
use Carbon\CarbonImmutable;
use DomainException;
use Money\Money;
use UnexpectedValueException;

final class EarningLine
{
    private EarningLineId $id;
    private Money $systemAmount;
    private Money $currentAmount;
    private int $version = 0;

    /** @var array<string, ManualAdjustment> */
    private array $adjustments = [];

    /** @var list<EarningLineEvent> */
    private array $recordedEvents = [];

    private function __construct()
    {
    }

    public static function create(EarningLineId $id, Money $systemAmount, CarbonImmutable $recordedAt): self
    {
        $line = new self();
        $line->record(new EarningLineCreated($id, $systemAmount, $recordedAt));

        return $line;
    }

    /** @param list<EarningLineEvent> $events */
    public static function reconstitute(array $events): self
    {
        if ($events === [] || !$events[0] instanceof EarningLineCreated) {
            throw new UnexpectedValueException('An Earning Line stream must begin with Earning Line Created.');
        }

        $line = new self();
        foreach ($events as $event) {
            $line->apply($event);
        }

        return $line;
    }

    public function updateSystemAmount(Money $systemAmount, CarbonImmutable $recordedAt): void
    {
        // The existence of any adjustment locks the baseline, even when their sum is zero.
        if ($this->adjustments !== []) {
            return;
        }

        $this->assertSameCurrency($systemAmount);
        if ($this->systemAmount->equals($systemAmount)) {
            return;
        }

        $this->record(new SystemAmountUpdated($systemAmount, $recordedAt));
    }

    public function addManualAdjustment(ManualAdjustment $adjustment): void
    {
        $this->record(new ManualAdjustmentAdded($adjustment));
    }

    public function id(): EarningLineId
    {
        return $this->id;
    }

    public function systemAmount(): Money
    {
        return $this->systemAmount;
    }

    public function currentAmount(): Money
    {
        return $this->currentAmount;
    }

    /** @return list<ManualAdjustment> */
    public function adjustments(): array
    {
        return array_values($this->adjustments);
    }

    public function version(): int
    {
        return $this->version;
    }

    /** @return list<EarningLineEvent> */
    public function recordedEvents(): array
    {
        return $this->recordedEvents;
    }

    public function markEventsCommitted(): void
    {
        $this->recordedEvents = [];
    }

    private function record(EarningLineEvent $event): void
    {
        $this->apply($event);
        $this->recordedEvents[] = $event;
    }

    private function apply(EarningLineEvent $event): void
    {
        if ($event instanceof EarningLineCreated) {
            if ($this->version !== 0) {
                throw new UnexpectedValueException('An Earning Line cannot be created twice.');
            }

            $this->id = $event->earningLineId;
            $this->systemAmount = $event->systemAmount;
            $this->currentAmount = $event->systemAmount;
        } elseif ($event instanceof SystemAmountUpdated) {
            if ($this->adjustments !== []) {
                throw new UnexpectedValueException('A frozen System Amount cannot be updated in a saved stream.');
            }

            $this->assertSameCurrency($event->systemAmount);
            $this->systemAmount = $event->systemAmount;
            $this->currentAmount = $event->systemAmount;
        } elseif ($event instanceof ManualAdjustmentAdded) {
            $adjustment = $event->adjustment;
            $this->assertSameCurrency($adjustment->amount);
            if (isset($this->adjustments[$adjustment->id->value])) {
                throw new DomainException('This Manual Adjustment Id already exists on the Earning Line.');
            }

            $this->currentAmount = $this->currentAmount->add($adjustment->amount);
            $this->adjustments[$adjustment->id->value] = $adjustment;
        } else {
            throw new UnexpectedValueException('Unsupported Earning Line event.');
        }

        ++$this->version;
    }

    private function assertSameCurrency(Money $amount): void
    {
        if (!$this->systemAmount->isSameCurrency($amount)) {
            throw new DomainException('Amounts must use the Earning Line currency.');
        }
    }
}
