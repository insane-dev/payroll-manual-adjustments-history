<?php

declare(strict_types=1);

namespace Alcor\Payroll\Application;

final readonly class GetAdjustmentHistoryHandler
{
    public function __construct(private EarningLineRepository $repository)
    {
    }

    public function handle(GetAdjustmentHistory $query): AdjustmentHistory
    {
        $line = $this->repository->get($query->earningLineId);

        return new AdjustmentHistory(
            $line->id(),
            $line->systemAmount(),
            $line->adjustments(),
            $line->currentAmount(),
        );
    }
}
