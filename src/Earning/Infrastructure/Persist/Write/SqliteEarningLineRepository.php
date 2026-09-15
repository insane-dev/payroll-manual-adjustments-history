<?php

declare(strict_types=1);

namespace App\Earning\Infrastructure\Persist\Write;

use App\Earning\Application\Exception\ConcurrentStreamWrite;
use App\Earning\Application\Exception\EarningLineNotFound;
use App\Earning\Domain\Entity\EarningLine;
use App\Earning\Domain\Repository\EarningLineRepository;
use App\Earning\Domain\Value\EarningLineId;
use App\Earning\Infrastructure\Mapper\EarningLineMapper;
use PDO;
use PDOStatement;
use RuntimeException;
use Throwable;

final readonly class SqliteEarningLineRepository implements EarningLineRepository
{
    public function __construct(
        private PDO $connection,
        private EarningLineMapper $earningLineMapper = new EarningLineMapper(),
    ) {
        $connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $connection->exec('PRAGMA foreign_keys = ON');
        $connection->exec('PRAGMA busy_timeout = 5000');
        $schema = file_get_contents(__DIR__ . '/schema.sql');
        if ($schema === false) {
            throw new RuntimeException('Cannot read the SQLite schema.');
        }
        $connection->exec($schema);
    }

    /** @phpstan-impure */
    public function get(EarningLineId $id): EarningLine
    {
        // A read transaction keeps the state and its history on the same database snapshot.
        $this->connection->beginTransaction();
        try {
            $query = $this->execute('SELECT * FROM earning_lines WHERE id = :id', ['id' => $this->earningLineMapper->binaryId($id->value)]);
            $state = $query->fetch(PDO::FETCH_ASSOC);
            $query->closeCursor();
            if ($state === false) {
                throw new EarningLineNotFound('Earning Line not found: ' . $id->value);
            }
            $query = $this->execute('SELECT * FROM earning_line_adjustments WHERE earning_line_id = :id ORDER BY sequence', ['id' => $this->earningLineMapper->binaryId($id->value)]);
            $rows = array_values($query->fetchAll(PDO::FETCH_ASSOC));
            $this->connection->commit();
        } catch (Throwable $exception) {
            $this->connection->rollBack();
            throw $exception;
        }

        return $this->earningLineMapper->restore($state, $rows);
    }

    public function save(EarningLine $line): void
    {
        if ($line->persistedVersion() === $line->version()) {
            return;
        }
        $this->connection->exec('BEGIN IMMEDIATE');
        try {
            $query = $this->execute('SELECT version FROM earning_lines WHERE id = :id', ['id' => $this->earningLineMapper->binaryId($line->id()->value)]);
            $actualVersion = $query->fetchColumn();
            $query->closeCursor();
            if (($actualVersion === false ? 0 : $actualVersion) !== $line->persistedVersion()) {
                throw new ConcurrentStreamWrite('Earning Line changed. Reload before retrying: ' . $line->id()->value);
            }
            $state = $this->earningLineMapper->toStateRow($line);
            if ($actualVersion === false) {
                $this->execute('INSERT INTO earning_lines (id, initial_amount, system_amount, current_amount, currency, manually_adjusted, version, created_at) VALUES (:id, :initial_amount, :system_amount, :current_amount, :currency, :manually_adjusted, :version, :created_at)', $state);
            } else {
                $this->execute('UPDATE earning_lines SET initial_amount = :initial_amount, system_amount = :system_amount, current_amount = :current_amount, currency = :currency, manually_adjusted = :manually_adjusted, version = :version, created_at = :created_at WHERE id = :id', $state);
            }
            $sequence = count($line->adjustments()) - count($line->pendingAdjustments());
            foreach ($line->pendingAdjustments() as $adjustment) {
                $this->execute(
                    'INSERT INTO earning_line_adjustments (earning_line_id, id, sequence, type, amount, comment, author_id, recorded_at) VALUES (:line_id, :id, :sequence, :type, :amount, :comment, :author_id, :recorded_at)',
                    $this->earningLineMapper->toAdjustmentRow($line->id(), ++$sequence, $adjustment),
                );
            }
            $this->connection->exec('COMMIT');
        } catch (Throwable $exception) {
            $this->connection->exec('ROLLBACK');
            throw $exception;
        }
        $line->markPersisted();
    }

    /** @param array<string, int|string|null> $parameters */
    private function execute(string $sql, array $parameters): PDOStatement
    {
        $statement = $this->connection->prepare($sql);
        foreach ($parameters as $name => $value) {
            $type = match (true) {
                $value === null => PDO::PARAM_NULL,
                in_array($name, ['id', 'line_id', 'author_id'], true) => PDO::PARAM_LOB,
                is_int($value) => PDO::PARAM_INT,
                default => PDO::PARAM_STR,
            };
            $statement->bindValue(':' . $name, $value, $type);
        }
        $statement->execute();

        return $statement;
    }
}
