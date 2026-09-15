<?php

declare(strict_types=1);

namespace Alcor\Payroll\Domain\Event;

use Alcor\Payroll\Domain\EarningLineId;
use Carbon\CarbonImmutable;
use Money\Money;

final readonly class EarningLineCreated implements EarningLineEvent
{
    public CarbonImmutable $recordedAt;

    public function __construct(
        public EarningLineId $earningLineId,
        public Money $systemAmount,
        CarbonImmutable $recordedAt,
    ) {
        $this->recordedAt = $recordedAt->utc();
    }
}
