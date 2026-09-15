<?php

declare(strict_types=1);

namespace App\Earning\Application\Query\Handler;

use App\Earning\Application\Query\GetAdjustmentHistory;
use App\Earning\Application\Query\Result\AdjustmentHistory;
use App\Earning\Domain\Repository\EarningLineRepository;

final readonly class GetAdjustmentHistoryHandler
{
    public function __construct(
        private EarningLineRepository $earningLines,
    ) {}

    /** @phpstan-impure */
    public function handle(GetAdjustmentHistory $query): AdjustmentHistory
    {
        $line = $this->earningLines->get($query->earningLineId);

        return new AdjustmentHistory(
            $line->id(),
            $line->systemAmount(),
            $line->adjustments(),
            $line->currentAmount(),
            $line->initialAmount(),
            $line->isManuallyAdjusted(),
        );
    }
}
