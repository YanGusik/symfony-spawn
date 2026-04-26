<?php

namespace Spawn\Symfony\Tests\Integration\Pgsql;

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\DriverManager;
use Spawn\Symfony\Database\AsyncConnection;
use Spawn\Symfony\Tests\AsyncTestCase;

abstract class PgsqlTestCase extends AsyncTestCase
{
    protected function setUp(): void
    {
        if (!getenv('PGSQL_HOST')) {
            $this->markTestSkipped('PostgreSQL env vars not set (PGSQL_HOST missing)');
        }
    }

    protected function makeConnection(): AsyncConnection
    {
        /** @var AsyncConnection */
        return DriverManager::getConnection([
            'driver'        => 'pdo_pgsql',
            'host'          => getenv('PGSQL_HOST'),
            'port'          => (int) (getenv('PGSQL_PORT') ?: 5432),
            'dbname'        => getenv('PGSQL_DB'),
            'user'          => getenv('PGSQL_USER'),
            'password'      => getenv('PGSQL_PASSWORD'),
            'wrapperClass'  => AsyncConnection::class,
            'driverOptions' => [
                \PDO::ATTR_POOL_ENABLED => true,
                \PDO::ATTR_POOL_MAX     => 5,
            ],
        ], new Configuration());
    }
}
