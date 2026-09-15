<?php

declare(strict_types=1);

namespace Alcor\Payroll\Application;

use Alcor\Payroll\Domain\EarningLineId;
use Money\Money;

final readonly class UpdateSystemAmount
{
    public function __construct(public EarningLineId $earningLineId, public Money $systemAmount)
    {
    }
}
