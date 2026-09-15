<?php

declare(strict_types=1);

namespace Alcor\Payroll\Domain\Event;

use Alcor\Payroll\Domain\ManualAdjustment;

final readonly class ManualAdjustmentAdded implements EarningLineEvent
{
    public function __construct(public ManualAdjustment $adjustment)
    {
    }
}
