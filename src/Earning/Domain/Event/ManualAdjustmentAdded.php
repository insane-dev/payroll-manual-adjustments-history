<?php

declare(strict_types=1);

namespace App\Earning\Domain\Event;

use App\Earning\Domain\Entity\ManualAdjustment;

final readonly class ManualAdjustmentAdded implements EarningLineEvent
{
    public function __construct(public ManualAdjustment $adjustment)
    {
    }
}
