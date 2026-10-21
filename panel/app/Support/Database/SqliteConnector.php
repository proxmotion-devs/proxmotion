<?php

declare(strict_types=1);

namespace App\Support\Database;

use Illuminate\Database\Connectors\SQLiteConnector as BaseConnector;
use PDO;

/**
 * Honours the connection's `transaction_mode` on PHP 8.3 too. Without it,
 * transactions there are DEFERRED: one that reads and then writes, like the
 * database queue reserving a job, fails at once with "database is locked"
 * when another process wrote in between (the busy timeout does not apply),
 * and Laravel then fails the job it was reserving.
 */
class SqliteConnector extends BaseConnector
{
    private const MODES = ['DEFERRED', 'IMMEDIATE', 'EXCLUSIVE'];

    private ?string $transactionMode = null;

    /**
     * @param  array<string, mixed>  $config
     */
    public function connect(array $config): PDO
    {
        $mode = strtoupper((string) ($config['transaction_mode'] ?? ''));
        $this->transactionMode = in_array($mode, self::MODES, true) && $mode !== 'DEFERRED' ? $mode : null;

        return parent::connect($config);
    }

    /**
     * @param  string  $dsn
     * @param  string  $username
     * @param  string  $password
     * @param  array<int, mixed>  $options
     */
    protected function createPdoConnection($dsn, $username, #[\SensitiveParameter] $password, $options): PDO
    {
        // PHP 8.4+: Laravel's SQLiteConnection begins with the mode itself.
        if ($this->transactionMode === null || version_compare(PHP_VERSION, '8.4.0', '>=')) {
            return parent::createPdoConnection($dsn, $username, $password, $options);
        }

        $pdo = new SqliteTransactionPdo($dsn, $username, $password, $options);
        $pdo->useTransactionMode($this->transactionMode);

        return $pdo;
    }
}
