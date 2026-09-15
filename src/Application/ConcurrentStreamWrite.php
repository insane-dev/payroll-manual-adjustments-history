<?php

declare(strict_types=1);

namespace Alcor\Payroll\Application;

use RuntimeException;

final class ConcurrentStreamWrite extends RuntimeException
{
}
