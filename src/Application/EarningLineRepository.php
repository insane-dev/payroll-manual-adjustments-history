<?php

declare(strict_types=1);

namespace Alcor\Payroll\Application;

use Alcor\Payroll\Domain\EarningLine;
use Alcor\Payroll\Domain\EarningLineId;
use UnexpectedValueException;

final readonly class EarningLineRepository
{
    public function __construct(private EventStore $eventStore)
    {
    }

    public function get(EarningLineId $id): EarningLine
    {
        $events = $this->eventStore->load($id);
        if ($events === []) {
            throw new EarningLineNotFound('Earning Line not found: ' . $id->value);
        }

        $line = EarningLine::reconstitute($events);
        if ($line->id()->value !== $id->value) {
            throw new UnexpectedValueException('The saved Earning Line Id does not match its stream.');
        }

        return $line;
    }

    public function save(EarningLine $line): void
    {
        $events = $line->recordedEvents();
        if ($events === []) {
            return;
        }

        $this->eventStore->append($line->id(), $line->version() - count($events), $events);
        $line->markEventsCommitted();
    }
}
