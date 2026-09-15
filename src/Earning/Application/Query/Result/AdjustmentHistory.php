<?php

declare(strict_types=1);

namespace App\Earning\Application\Query\Result;

use App\Earning\Domain\Entity\ManualAdjustment;
use App\Earning\Domain\Value\EarningLineId;
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
