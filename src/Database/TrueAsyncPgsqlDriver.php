<?php

namespace Spawn\Symfony\Database;

use Doctrine\DBAL\Driver\AbstractPostgreSQLDriver;
use Doctrine\DBAL\Driver\PDO\Connection;
use Doctrine\DBAL\Driver\PDO\Exception;

use const PHP_VERSION_ID;

/**
 * PDO PostgreSQL driver compatible with TrueAsync PHP 8.6+.
 *
 * TrueAsync changes the PDO constructor signature: arg3 is $errmode (int)
 * instead of $password (string), and the password must be embedded in the DSN.
 *
 * Usage in doctrine.yaml:
 *   doctrine:
 *       dbal:
 *           driver_class: Spawn\Symfony\Database\TrueAsyncPgsqlDriver
 */
class TrueAsyncPgsqlDriver extends AbstractPostgreSQLDriver
{
    public function connect(array $params): Connection
    {
        $driverOptions = $params['driverOptions'] ?? [];

        if (!empty($params['persistent'])) {
            $driverOptions[\PDO::ATTR_PERSISTENT] = true;
        }

        try {
            // TrueAsync requires: password in DSN, arg3 = errmode constant
            $dsn = $this->buildDsn($params, embedPassword: true);
            $pdo = new \PDO($dsn, $params['user'] ?? null, \PDO::ERRMODE_EXCEPTION, $driverOptions);
        } catch (\PDOException $e) {
            throw Exception::new($e);
        }

        $disablePreparesAttr = PHP_VERSION_ID >= 80400 && class_exists(\Pdo\Pgsql::class)
            ? \Pdo\Pgsql::ATTR_DISABLE_PREPARES
            : \PDO::PGSQL_ATTR_DISABLE_PREPARES;

        if (!isset($driverOptions[$disablePreparesAttr]) || $driverOptions[$disablePreparesAttr] === true) {
            $pdo->setAttribute($disablePreparesAttr, true);
        }

        $connection = new Connection($pdo);

        if (isset($params['charset'])) {
            $connection->exec('SET NAMES \'' . $params['charset'] . '\'');
        }

        return $connection;
    }

    private function buildDsn(array $params, bool $embedPassword = false): string
    {
        $dsn = 'pgsql:';

        if (!empty($params['host'])) {
            $dsn .= 'host=' . $params['host'] . ';';
        }
        if (isset($params['port']) && $params['port'] !== '') {
            $dsn .= 'port=' . $params['port'] . ';';
        }
        if (isset($params['dbname'])) {
            $dsn .= 'dbname=' . $params['dbname'] . ';';
        }
        if ($embedPassword && !empty($params['password'])) {
            $dsn .= 'password=' . $params['password'] . ';';
        }
        if (isset($params['sslmode'])) {
            $dsn .= 'sslmode=' . $params['sslmode'] . ';';
        }
        if (isset($params['sslrootcert'])) {
            $dsn .= 'sslrootcert=' . $params['sslrootcert'] . ';';
        }
        if (isset($params['sslcert'])) {
            $dsn .= 'sslcert=' . $params['sslcert'] . ';';
        }
        if (isset($params['sslkey'])) {
            $dsn .= 'sslkey=' . $params['sslkey'] . ';';
        }
        if (isset($params['application_name'])) {
            $dsn .= 'application_name=' . $params['application_name'] . ';';
        }

        return $dsn;
    }
}
