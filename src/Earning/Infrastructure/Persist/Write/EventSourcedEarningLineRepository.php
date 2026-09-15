<?php

declare(strict_types=1);

namespace App\Earning\Infrastructure\Persist\Write;

use App\Earning\Application\Exception\EarningLineNotFound;
use App\Earning\Application\Port\EventStore;
use App\Earning\Domain\Entity\EarningLine;
use App\Earning\Domain\Repository\EarningLineRepository;
use App\Earning\Domain\Value\EarningLineId;
use UnexpectedValueException;

final readonly class EventSourcedEarningLineRepository implements EarningLineRepository
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
