<?php

declare(strict_types=1);

namespace Alcor\Payroll\Application;

use Alcor\Payroll\Domain\ManualAdjustment;

final readonly class AddManualAdjustmentHandler
{
    public function __construct(private EarningLineRepository $repository, private Clock $clock)
    {
    }

    public function handle(AddManualAdjustment $command): void
    {
        $line = $this->repository->get($command->earningLineId);
        $line->addManualAdjustment(new ManualAdjustment(
            $command->adjustmentId,
            $command->amount,
            $command->comment,
            $command->authorId,
            $this->clock->now(),
        ));
        $this->repository->save($line);
    }
}
