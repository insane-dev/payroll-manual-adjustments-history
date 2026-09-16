<?php

declare(strict_types=1);

namespace App\Shared\Application;

use Carbon\CarbonImmutable;
use Psr\Clock\ClockInterface;

interface Clock extends ClockInterface
{
    public function now(): CarbonImmutable;
}
