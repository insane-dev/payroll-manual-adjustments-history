<?php

declare(strict_types=1);

namespace App\Earning\Application\Command\Handler;

use App\Earning\Application\Command\AddManualAdjustment;
use App\Earning\Domain\Entity\EarningLineAdjustment;
use App\Earning\Domain\Repository\EarningLineRepository;
use App\Shared\Application\Clock;

final readonly class AddManualAdjustmentHandler
{
    public function __construct(
        private EarningLineRepository $earningLines,
        private Clock $clock,
    ) {}

    public function handle(AddManualAdjustment $command): void
    {
        $line = $this->earningLines->get($command->earningLineId);
        $line->addManualAdjustment(new EarningLineAdjustment(
            $command->adjustmentId,
            $command->amount,
            $command->comment,
            $command->authorId,
            $this->clock->now(),
        ));
        $this->earningLines->save($line);
    }
}
