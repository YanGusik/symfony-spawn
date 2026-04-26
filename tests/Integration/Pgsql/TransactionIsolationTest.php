<?php

namespace Spawn\Symfony\Tests\Integration\Pgsql;

use function Async\delay;

class TransactionIsolationTest extends PgsqlTestCase
{
    // ── nesting level isolation ───────────────────────────────────────────────

    public function test_each_coroutine_starts_at_level_zero(): void
    {
        $conn = $this->makeConnection();

        $results = $this->runParallel([
            'a' => function () use ($conn) {
                $conn->beginTransaction();
                delay(100);
                $conn->rollBack();
                return 'ok';
            },
            'b' => function () use ($conn) {
                delay(20); // starts while A is in transaction
                return $conn->getTransactionNestingLevel();
            },
        ]);

        $this->assertSame(0, $results['b'], 'B must not see A transaction level');
    }

    public function test_nesting_level_isolated_during_concurrent_transactions(): void
    {
        $conn = $this->makeConnection();

        $results = $this->runParallel([
            'a' => function () use ($conn) {
                $this->assertSame(0, $conn->getTransactionNestingLevel());
                $conn->beginTransaction();
                $this->assertSame(1, $conn->getTransactionNestingLevel());

                delay(100);

                $level = $conn->getTransactionNestingLevel();
                $conn->rollBack();

                return $level;
            },
            'b' => function () use ($conn) {
                delay(20);

                $this->assertSame(0, $conn->getTransactionNestingLevel(), 'B must not see A level');
                $conn->beginTransaction();
                $this->assertSame(1, $conn->getTransactionNestingLevel(), 'B: real BEGIN, not SAVEPOINT');
                $conn->rollBack();

                return $conn->getTransactionNestingLevel();
            },
        ]);

        $this->assertSame(1, $results['a'], 'A: level 1 during transaction');
        $this->assertSame(0, $results['b'], 'B: level 0 after rollBack');
    }

    public function test_nested_transactions_do_not_bleed_between_coroutines(): void
    {
        $conn = $this->makeConnection();

        $results = $this->runParallel([
            'a' => function () use ($conn) {
                $conn->beginTransaction(); // level 1
                $conn->beginTransaction(); // level 2 — SAVEPOINT

                delay(100);

                $level = $conn->getTransactionNestingLevel();
                $conn->rollBack();
                $conn->rollBack();

                return $level;
            },
            'b' => function () use ($conn) {
                delay(20);

                $this->assertSame(0, $conn->getTransactionNestingLevel());
                $conn->beginTransaction();
                $level = $conn->getTransactionNestingLevel();
                $conn->rollBack();

                return $level;
            },
        ]);

        $this->assertSame(2, $results['a'], 'A: nested at level 2');
        $this->assertSame(1, $results['b'], 'B: independent level 1, not nested into A');
    }

    // ── data isolation ────────────────────────────────────────────────────────

    public function test_uncommitted_data_not_visible_to_other_coroutine(): void
    {
        $conn = $this->makeConnection();
        $conn->executeStatement('CREATE TABLE IF NOT EXISTS tx_test (id SERIAL PRIMARY KEY, val TEXT)');
        $conn->executeStatement('TRUNCATE tx_test');

        $results = $this->runParallel([
            'a' => function () use ($conn) {
                $conn->beginTransaction();
                $conn->executeStatement("INSERT INTO tx_test (val) VALUES ('from_a')");

                delay(100);

                $conn->rollBack();

                return (int) $conn->fetchOne('SELECT COUNT(*) FROM tx_test WHERE val = ?', ['from_a']);
            },
            'b' => function () use ($conn) {
                delay(50);
                return (int) $conn->fetchOne('SELECT COUNT(*) FROM tx_test WHERE val = ?', ['from_a']);
            },
        ]);

        $this->assertSame(0, $results['a'], 'A rolled back — row gone');
        $this->assertSame(0, $results['b'], 'B must not see uncommitted data from A');
    }

    // ── autoCommit=false ──────────────────────────────────────────────────────

    public function test_autocommit_false_auto_begins_on_first_use(): void
    {
        $conn = $this->makeConnection();
        $conn->setAutoCommit(false);

        $results = $this->runParallel([
            'a' => function () use ($conn) {
                // First DB operation triggers connect() → auto-begin
                $conn->executeStatement('SELECT 1');
                $level = $conn->getTransactionNestingLevel();

                $conn->rollBack(); // cleanup

                return $level;
            },
        ]);

        $this->assertSame(1, $results['a'], 'autoCommit=false: auto-begin on first use');
        $conn->setAutoCommit(true);
    }

    public function test_autocommit_false_opens_new_tx_after_commit(): void
    {
        $conn = $this->makeConnection();
        $conn->setAutoCommit(false);

        $results = $this->runParallel([
            'a' => function () use ($conn) {
                $conn->executeStatement('SELECT 1'); // triggers auto-begin → level 1
                $this->assertSame(1, $conn->getTransactionNestingLevel(), 'auto-begin on first use');

                $conn->commit(); // level 0 → auto-begin → level 1

                $level = $conn->getTransactionNestingLevel();
                $conn->rollBack(); // cleanup

                return $level;
            },
        ]);

        $this->assertSame(1, $results['a'], 'new TX opened automatically after commit');
        $conn->setAutoCommit(true);
    }

    public function test_autocommit_false_opens_new_tx_after_rollback(): void
    {
        $conn = $this->makeConnection();
        $conn->setAutoCommit(false);

        $results = $this->runParallel([
            'a' => function () use ($conn) {
                $conn->executeStatement('SELECT 1'); // triggers auto-begin → level 1
                $conn->rollBack(); // level 0 → auto-begin → level 1

                $level = $conn->getTransactionNestingLevel();
                $conn->rollBack(); // cleanup

                return $level;
            },
        ]);

        $this->assertSame(1, $results['a'], 'new TX opened automatically after rollBack');
        $conn->setAutoCommit(true);
    }

    public function test_autocommit_false_tx_isolated_between_coroutines(): void
    {
        $conn = $this->makeConnection();
        $conn->setAutoCommit(false);

        $results = $this->runParallel([
            'a' => function () use ($conn) {
                $conn->executeStatement('SELECT 1'); // auto-begin → level 1
                $this->assertSame(1, $conn->getTransactionNestingLevel());

                delay(100);

                // A's level must not be affected by B's commit
                $level = $conn->getTransactionNestingLevel();
                $conn->rollBack();

                return $level;
            },
            'b' => function () use ($conn) {
                delay(20);

                $conn->executeStatement('SELECT 1'); // auto-begin → level 1
                $this->assertSame(1, $conn->getTransactionNestingLevel());

                $conn->commit(); // → auto-begin → still level 1
                $level = $conn->getTransactionNestingLevel();
                $conn->rollBack();

                return $level;
            },
        ]);

        $this->assertSame(1, $results['a'], 'A: level unaffected by B commit');
        $this->assertSame(1, $results['b'], 'B: new TX opened after commit');

        $conn->setAutoCommit(true);
    }
}
