<?php

declare(strict_types=1);

namespace App\Earning\Application\Command\Handler;

use App\Earning\Application\Command\CreateEarningLine;
use App\Earning\Domain\Entity\EarningLine;
use App\Earning\Domain\Repository\EarningLineRepository;
use App\Shared\Application\Clock;

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
