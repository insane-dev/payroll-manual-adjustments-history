<?php

declare(strict_types=1);

namespace App\Earning\Domain\Repository;

use App\Earning\Domain\Entity\EarningLine;
use App\Earning\Domain\Value\EarningLineId;

interface EarningLineRepository
{
    public function get(EarningLineId $id): EarningLine;

    public function save(EarningLine $line): void;
}
