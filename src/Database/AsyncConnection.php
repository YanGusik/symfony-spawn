<?php

namespace Spawn\Symfony\Database;

use Doctrine\DBAL\Connection as DBALConnection;
use Doctrine\DBAL\Exception\CommitFailedRollbackOnly;
use Doctrine\DBAL\Exception\NoActiveTransaction;
use Spawn\Symfony\ScopedService;

use function Async\current_context;

/**
 * Per-coroutine transaction nesting for Doctrine DBAL.
 *
 * Problem: DBALConnection::$transactionNestingLevel is private and shared
 * across all coroutines using the same Connection object. Without this class:
 *
 *   Coroutine A: beginTransaction() → level 0→1 → yields on DB I/O
 *   Coroutine B: reads level = 1 → creates SAVEPOINT instead of real BEGIN
 *   Coroutine B: commit() → tries releaseSavepoint → "no such savepoint" error
 *
 * Solution: full override of all transaction methods without calling parent.
 * Transaction state (nesting level, rollback-only flag) lives in current_context()
 * so each coroutine has its own isolated state.
 *
 * autoCommit=false is stored as a regular instance property — it is a
 * connection-level setting, not per-request, so sharing across coroutines
 * is correct behaviour.
 */
class AsyncConnection extends DBALConnection
{
    private bool $asyncAutoCommit = true;

    public function setAutoCommit(bool $autoCommit): void
    {
        $this->asyncAutoCommit = $autoCommit;

        // Parent call is safe: parent's $transactionNestingLevel is always 0
        // (we never update it), so parent's commitAll() branch is never reached.
        parent::setAutoCommit($autoCommit);
    }

    public function connect(): \Doctrine\DBAL\Driver\Connection
    {
        // Recursion guard: beginTransaction() sets DB_IN_BEGIN before calling connect(),
        // so we skip auto-begin logic entirely to prevent double-BEGIN.
        if (current_context()->findLocal(ScopedService::DB_IN_BEGIN) ?? false) {
            return parent::connect();
        }

        if ($this->asyncAutoCommit === false && $this->txLevel() === 0) {
            $this->beginTransaction();
        }

        return parent::connect();
    }

    /** @throws \Doctrine\DBAL\Exception */
    public function beginTransaction(): void
    {
        current_context()->set(ScopedService::DB_IN_BEGIN, true, replace: true);
        try {
            $levelBefore = $this->txLevel();
            $connection  = $this->connect();

            // parent::connect() with autoCommit=false calls $this->beginTransaction()
            // on first connection, which increments the level.  If that happened, bail.
            if ($this->txLevel() !== $levelBefore) {
                return;
            }

            if ($levelBefore === 0) {
                try {
                    $connection->beginTransaction();
                } catch (\Doctrine\DBAL\Driver\Exception $e) {
                    throw $this->convertException($e);
                }
            } else {
                $this->createSavepoint($this->savepointName($levelBefore + 1));
            }

            $this->setTxLevel($levelBefore + 1);
        } finally {
            current_context()->set(ScopedService::DB_IN_BEGIN, false, replace: true);
        }
    }

    /** @throws \Doctrine\DBAL\Exception */
    public function commit(): void
    {
        $level = $this->txLevel();

        if ($level === 0) {
            throw NoActiveTransaction::new();
        }

        if ($this->txRollbackOnly()) {
            throw CommitFailedRollbackOnly::new();
        }

        $connection = $this->connect();

        if ($level === 1) {
            try {
                $connection->commit();
            } catch (\Doctrine\DBAL\Driver\Exception $e) {
                throw $this->convertException($e);
            }
        } else {
            $this->releaseSavepoint($this->savepointName($level));
        }

        $this->setTxLevel($level - 1);
        $this->setTxRollbackOnly(false);

        // autoCommit=false: immediately open a new transaction after each commit
        if (!$this->asyncAutoCommit && $level === 1) {
            $this->beginTransaction();
        }
    }

    /** @throws \Doctrine\DBAL\Exception */
    public function rollBack(): void
    {
        $level = $this->txLevel();

        if ($level === 0) {
            throw NoActiveTransaction::new();
        }

        $connection = $this->connect();

        if ($level === 1) {
            try {
                $connection->rollBack();
            } catch (\Doctrine\DBAL\Driver\Exception $e) {
                throw $this->convertException($e);
            }

            $this->setTxLevel(0);
            $this->setTxRollbackOnly(false);

            // autoCommit=false: immediately open a new transaction after rollback
            if (!$this->asyncAutoCommit) {
                $this->beginTransaction();
            }
        } else {
            $this->rollbackSavepoint($this->savepointName($level));
            $this->setTxLevel($level - 1);
        }
    }

    public function getTransactionNestingLevel(): int
    {
        return $this->txLevel();
    }

    public function isTransactionActive(): bool
    {
        return $this->txLevel() > 0;
    }

    private function txLevel(): int
    {
        return current_context()->findLocal(ScopedService::DB_TX_NESTING) ?? 0;
    }

    private function setTxLevel(int $level): void
    {
        current_context()->set(ScopedService::DB_TX_NESTING, $level, replace: true);
    }

    private function txRollbackOnly(): bool
    {
        return current_context()->findLocal(ScopedService::DB_ROLLBACK_ONLY) ?? false;
    }

    private function setTxRollbackOnly(bool $value): void
    {
        current_context()->set(ScopedService::DB_ROLLBACK_ONLY, $value, replace: true);
    }

    private function savepointName(int $level): string
    {
        return 'DOCTRINE_' . $level;
    }
}
