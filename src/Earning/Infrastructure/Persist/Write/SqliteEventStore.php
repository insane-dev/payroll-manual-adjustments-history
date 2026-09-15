<?php

declare(strict_types=1);

namespace App\Earning\Infrastructure\Persist\Write;

use App\Earning\Application\Exception\ConcurrentStreamWrite;
use App\Earning\Application\Port\EventStore;
use App\Earning\Domain\Value\EarningLineId;
use App\Earning\Infrastructure\Mapper\EventSerializer;
use PDO;
use Throwable;
use UnexpectedValueException;

final readonly class SqliteEventStore implements EventStore
{
    public function __construct(private PDO $connection, private EventSerializer $serializer)
    {
        $this->connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->connection->exec('PRAGMA busy_timeout = 5000');
        $this->connection->exec(file_get_contents(__DIR__ . '/schema.sql'));
    }

    public function load(EarningLineId $id): array
    {
        $statement = $this->connection->prepare(
            'SELECT version, event_type, payload FROM earning_line_events WHERE stream_id = :id ORDER BY version',
        );
        $statement->execute(['id' => $id->value]);

        $events = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if ((int) $row['version'] !== count($events) + 1) {
                throw new UnexpectedValueException('The event stream contains a version gap.');
            }
            $events[] = $this->serializer->decode($row['event_type'], $row['payload']);
        }

        return $events;
    }

    public function append(EarningLineId $id, int $expectedVersion, array $events): void
    {
        if ($events === []) {
            return;
        }

        // Acquire the write lock before checking the version, so the check and append are atomic.
        $this->connection->exec('BEGIN IMMEDIATE');
        try {
            $versionQuery = $this->connection->prepare(
                'SELECT COALESCE(MAX(version), 0) FROM earning_line_events WHERE stream_id = :id',
            );
            $versionQuery->execute(['id' => $id->value]);
            $actualVersion = (int) $versionQuery->fetchColumn();
            $versionQuery->closeCursor();

            if ($actualVersion !== $expectedVersion) {
                throw new ConcurrentStreamWrite(sprintf(
                    'Earning Line %s changed: expected version %d, found %d. Reload before retrying.',
                    $id->value,
                    $expectedVersion,
                    $actualVersion,
                ));
            }

            $insert = $this->connection->prepare(
                'INSERT INTO earning_line_events (stream_id, version, event_type, payload) VALUES (:id, :version, :type, :payload)',
            );
            foreach ($events as $event) {
                $encoded = $this->serializer->encode($event);
                $insert->execute([
                    'id' => $id->value,
                    'version' => ++$actualVersion,
                    'type' => $encoded['type'],
                    'payload' => $encoded['payload'],
                ]);
            }

            $this->connection->exec('COMMIT');
        } catch (Throwable $exception) {
            $this->connection->exec('ROLLBACK');
            throw $exception;
        }
    }
}
