<?php

declare(strict_types=1);

namespace App\Support\Database;

use PDO;
use PDOException;

/**
 * A SQLite PDO whose transactions start with BEGIN IMMEDIATE (or another
 * mode). Laravel applies `transaction_mode` only on PHP 8.4+, where PDO
 * knows whether SQLite is in a transaction; before that, PDO tracks it
 * itself, so this class begins, commits and rolls back with SQL and keeps
 * that state.
 */
class SqliteTransactionPdo extends PDO
{
    private string $transactionMode = 'DEFERRED';

    private bool $inTransaction = false;

    public function useTransactionMode(string $mode): void
    {
        $this->transactionMode = $mode;
    }

    public function beginTransaction(): bool
    {
        if ($this->inTransaction) {
            throw new PDOException('There is already an active transaction');
        }

        if ($this->exec("BEGIN {$this->transactionMode} TRANSACTION") === false) {
            return false;
        }

        $this->inTransaction = true;

        return true;
    }

    public function commit(): bool
    {
        if (! $this->inTransaction) {
            throw new PDOException('There is no active transaction');
        }

        // A failed COMMIT (e.g. SQLITE_BUSY) leaves the transaction open.
        if ($this->exec('COMMIT') === false) {
            return false;
        }

        $this->inTransaction = false;

        return true;
    }

    public function rollBack(): bool
    {
        if (! $this->inTransaction) {
            throw new PDOException('There is no active transaction');
        }

        $this->inTransaction = false;

        try {
            return $this->exec('ROLLBACK') !== false;
        } catch (PDOException $e) {
            // SQLite already rolled back on its own (e.g. after SQLITE_FULL).
            if (str_contains($e->getMessage(), 'no transaction is active')) {
                return true;
            }

            throw $e;
        }
    }

    public function inTransaction(): bool
    {
        return $this->inTransaction;
    }
}
