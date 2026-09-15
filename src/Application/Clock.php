<?php

declare(strict_types=1);

namespace Alcor\Payroll\Application;

use Carbon\CarbonImmutable;

interface Clock
{
    public function now(): CarbonImmutable;
}
