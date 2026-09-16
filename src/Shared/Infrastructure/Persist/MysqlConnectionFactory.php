<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Persist;

use PDO;

final class MysqlConnectionFactory
{
    public function create(string $dsn, string $user, string $password): PDO
    {
        $connection = new PDO($dsn, $user, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ]);
        $connection->exec("SET SESSION sql_mode = 'STRICT_ALL_TABLES,NO_ENGINE_SUBSTITUTION'");
        $connection->exec('SET SESSION innodb_lock_wait_timeout = 5');

        return $connection;
    }
}
