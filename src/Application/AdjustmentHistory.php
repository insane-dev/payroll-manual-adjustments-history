<?php

declare(strict_types=1);

namespace Alcor\Payroll\Application;

use Alcor\Payroll\Domain\EarningLineId;
use Alcor\Payroll\Domain\ManualAdjustment;
use Money\Money;

final readonly class AdjustmentHistory
{
    /** @param list<ManualAdjustment> $adjustments */
    public function __construct(
        public EarningLineId $earningLineId,
        public Money $systemAmount,
        public array $adjustments,
        public Money $currentAmount,
    ) {
    }
}
