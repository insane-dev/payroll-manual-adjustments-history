<?php

declare(strict_types=1);

namespace Alcor\Payroll\Application;

use Alcor\Payroll\Domain\EarningLine;

final readonly class CreateEarningLineHandler
{
    public function __construct(private EarningLineRepository $repository, private Clock $clock)
    {
    }

    public function handle(CreateEarningLine $command): void
    {
        $line = EarningLine::create($command->earningLineId, $command->systemAmount, $this->clock->now());
        $this->repository->save($line);
    }
}
