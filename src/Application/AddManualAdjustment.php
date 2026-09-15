<?php

declare(strict_types=1);

namespace Alcor\Payroll\Application;

use Alcor\Payroll\Domain\AdjustmentAuthorId;
use Alcor\Payroll\Domain\EarningLineId;
use Alcor\Payroll\Domain\ManualAdjustmentId;
use Money\Money;

final readonly class AddManualAdjustment
{
    public function __construct(
        public EarningLineId $earningLineId,
        public ManualAdjustmentId $adjustmentId,
        public Money $amount,
        public string $comment,
        public AdjustmentAuthorId $authorId,
    ) {
    }
}
