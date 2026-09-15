<?php

declare(strict_types=1);

namespace App\Earning\Domain\Event;

use Carbon\CarbonImmutable;
use Money\Money;

final readonly class SystemAmountUpdated implements EarningLineEvent
{
    public CarbonImmutable $recordedAt;

    public function __construct(
        public Money $systemAmount,
        CarbonImmutable $recordedAt,
    ) {
        $this->recordedAt = $recordedAt->utc();
    }
}
