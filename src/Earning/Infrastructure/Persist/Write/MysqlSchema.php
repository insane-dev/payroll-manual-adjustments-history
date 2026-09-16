<?php

declare(strict_types=1);

namespace App\Earning\Infrastructure\Persist\Write;

use PDO;
use RuntimeException;

final class MysqlSchema
{
    public function initialize(PDO $connection): void
    {
        if ($connection->inTransaction()) {
            throw new RuntimeException('Schema initialization must run outside a transaction.');
        }
        $schema = file_get_contents(__DIR__ . '/schema.sql');
        if ($schema === false) {
            throw new RuntimeException('Cannot read the MySQL schema.');
        }
        // The owned schema contains only CREATE TABLE statements, without embedded semicolons.
        foreach (explode(';', $schema) as $statement) {
            if (trim($statement) !== '') {
                $connection->exec($statement);
            }
        }
    }
}
