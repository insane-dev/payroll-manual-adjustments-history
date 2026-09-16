<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Persist;

use App\Shared\Infrastructure\Persist\MysqlConnectionFactory;
use PDO;

final class MysqlTestDatabase
{
    private string $name;
    private string $dsn;
    private string $user;
    private string $password;
    private PDO $administrator;

    public function __construct()
    {
        $this->name = 'payroll_test_' . bin2hex(random_bytes(8));
        $serverDsn = getenv('TEST_MYSQL_DSN') ?: 'mysql:host=mysql;charset=utf8mb4';
        $this->user = getenv('TEST_MYSQL_USER') ?: 'root';
        $this->password = getenv('TEST_MYSQL_PASSWORD') ?: 'root';
        $this->administrator = (new MysqlConnectionFactory())->create($serverDsn, $this->user, $this->password);
        $this->administrator->exec('CREATE DATABASE `' . $this->name . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_as_cs');
        $this->dsn = $serverDsn . ';dbname=' . $this->name;
    }

    public function connection(): PDO
    {
        return (new MysqlConnectionFactory())->create($this->dsn, $this->user, $this->password);
    }

    /** @return array<string, string> */
    public function environment(): array
    {
        return [
            'PAYROLL_DATABASE_DSN' => $this->dsn,
            'PAYROLL_DATABASE_USER' => $this->user,
            'PAYROLL_DATABASE_PASSWORD' => $this->password,
        ];
    }

    public function drop(): void
    {
        $this->administrator->exec('DROP DATABASE `' . $this->name . '`');
    }
}
