<?php

declare(strict_types=1);

namespace App\Earning\Application\Command;

use App\Earning\Domain\Value\AdjustmentAuthorId;
use App\Earning\Domain\Value\EarningLineId;
use App\Earning\Domain\Value\ManualAdjustmentId;
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
