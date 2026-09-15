<?php

declare(strict_types=1);

namespace Alcor\Payroll\Domain;

use Ramsey\Uuid\Uuid;

final readonly class EarningLineId
{
    public string $value;

    public function __construct(string $value)
    {
        $this->value = Uuid::fromString($value)->toString();
    }
}
