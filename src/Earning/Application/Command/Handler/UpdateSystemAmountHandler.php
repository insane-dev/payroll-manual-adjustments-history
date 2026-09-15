<?php

declare(strict_types=1);

namespace App\Earning\Application\Command\Handler;

use App\Earning\Application\Command\UpdateSystemAmount;
use App\Earning\Domain\Repository\EarningLineRepository;
use App\Shared\Application\Clock;

final readonly class UpdateSystemAmountHandler
{
    public function __construct(private EarningLineRepository $repository, private Clock $clock) {}

    public function handle(UpdateSystemAmount $command): void
    {
        $line = $this->repository->get($command->earningLineId);
        $line->updateSystemAmount($command->systemAmount, $this->clock->now());
        $this->repository->save($line);
    }
}
