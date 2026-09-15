<?php

declare(strict_types=1);

namespace App\Earning\Domain\Value;

enum EarningLineAdjustmentType: string
{
    case INITIAL = 'initial';
    case SYSTEM = 'system';
    case MANUAL = 'manual';

    public function isInitial(): bool
    {
        return $this === self::INITIAL;
    }

    public function isManual(): bool
    {
        return $this === self::MANUAL;
    }

    public function isSystem(): bool
    {
        return $this === self::SYSTEM;
    }
}
