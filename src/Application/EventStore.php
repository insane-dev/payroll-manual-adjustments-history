<?php

declare(strict_types=1);

namespace Alcor\Payroll\Application;

use Alcor\Payroll\Domain\EarningLineId;
use Alcor\Payroll\Domain\Event\EarningLineEvent;

interface EventStore
{
    /** @return list<EarningLineEvent> */
    public function load(EarningLineId $id): array;

    /** @param list<EarningLineEvent> $events */
    public function append(EarningLineId $id, int $expectedVersion, array $events): void;
}
