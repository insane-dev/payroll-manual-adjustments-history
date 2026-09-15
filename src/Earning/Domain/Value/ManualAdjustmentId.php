<?php

declare(strict_types=1);

namespace App\Earning\Domain\Value;

use Ramsey\Uuid\Uuid;

final readonly class ManualAdjustmentId
{
    public string $value;

    public function __construct(string $value)
    {
        $this->value = Uuid::fromString($value)->toString();
    }
}
