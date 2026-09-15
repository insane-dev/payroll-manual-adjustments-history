<?php

declare(strict_types=1);

namespace App\Earning\Application\Query;

use App\Earning\Domain\Value\EarningLineId;

final readonly class GetAdjustmentHistory
{
    public function __construct(public EarningLineId $earningLineId) {}
}
