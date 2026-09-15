<?php

declare(strict_types=1);

namespace Alcor\Payroll\Application;

use Alcor\Payroll\Domain\EarningLineId;

final readonly class GetAdjustmentHistory
{
    public function __construct(public EarningLineId $earningLineId)
    {
    }
}
