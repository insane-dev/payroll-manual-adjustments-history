<?php

declare(strict_types=1);

namespace Alcor\Payroll\Infrastructure;

use Alcor\Payroll\Application\Clock;
use Carbon\CarbonImmutable;

final class CarbonClock implements Clock
{
    public function now(): CarbonImmutable
    {
        return CarbonImmutable::now('UTC');
    }
}
