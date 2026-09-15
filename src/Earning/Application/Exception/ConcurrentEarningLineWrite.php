<?php

declare(strict_types=1);

namespace App\Earning\Application\Exception;

use RuntimeException;

final class ConcurrentEarningLineWrite extends RuntimeException {}
