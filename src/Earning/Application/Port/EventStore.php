<?php

declare(strict_types=1);

namespace App\Earning\Application\Port;

use App\Earning\Domain\Event\EarningLineEvent;
use App\Earning\Domain\Value\EarningLineId;

interface EventStore
{
    /** @return list<EarningLineEvent> */
    public function load(EarningLineId $id): array;

    /** @param list<EarningLineEvent> $events */
    public function append(EarningLineId $id, int $expectedVersion, array $events): void;
}
