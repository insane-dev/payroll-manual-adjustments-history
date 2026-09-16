<?php

declare(strict_types=1);

namespace App\Earning\Application\Command;

use App\Earning\Domain\Value\EarningLineId;
use Money\Money;

final readonly class UpdateSystemAmount
{
    public function __construct(
        public EarningLineId $earningLineId,
        public Money $systemAmount,
    ) {}
}
