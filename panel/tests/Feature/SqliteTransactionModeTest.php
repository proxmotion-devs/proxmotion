<?php

namespace Tests\Feature;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use PDO;
use PDOException;
use Tests\TestCase;

/**
 * Transactions on SQLite take the write lock when they begin
 * (`transaction_mode` IMMEDIATE), also on PHP 8.3: otherwise the database
 * queue's job reservation fails with "database is locked" and Laravel fails
 * the job (and RunServerTask::failed() its task).
 */
class SqliteTransactionModeTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        parent::setUp();

        $this->path = tempnam(sys_get_temp_dir(), 'proxmotion-sqlite-');

        config(['database.connections.sqlite_file' => [
            'driver' => 'sqlite',
            'database' => $this->path,
            'prefix' => '',
            'busy_timeout' => 0,
            'journal_mode' => 'wal',
            'transaction_mode' => 'IMMEDIATE',
        ]]);

        $this->connection()->statement('create table items (id integer primary key, name text)');
    }

    protected function tearDown(): void
    {
        DB::purge('sqlite_file');

        foreach (['', '-wal', '-shm'] as $suffix) {
            @unlink($this->path.$suffix);
        }

        parent::tearDown();
    }

    public function test_a_transaction_takes_the_write_lock_when_it_begins(): void
    {
        $this->connection()->transaction(function (): void {
            // Only a read so far: a DEFERRED transaction would not hold the lock yet.
            $this->connection()->table('items')->count();

            $this->expectException(PDOException::class);
            $this->expectExceptionMessage('database is locked');

            $other = new PDO('sqlite:'.$this->path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $other->exec('pragma busy_timeout = 0');
            $other->exec("insert into items (name) values ('other')");
        });
    }

    public function test_commits_rollbacks_and_nested_transactions_work(): void
    {
        $db = $this->connection();

        $db->transaction(fn () => $db->table('items')->insert(['name' => 'kept']));

        try {
            $db->transaction(function () use ($db): void {
                $db->table('items')->insert(['name' => 'rolled back']);

                throw new \RuntimeException('stop');
            });
        } catch (\RuntimeException) {
        }

        $db->transaction(function () use ($db): void {
            $db->table('items')->insert(['name' => 'outer']);

            try {
                $db->transaction(function () use ($db): void {
                    $db->table('items')->insert(['name' => 'inner']);

                    throw new \RuntimeException('stop');
                });
            } catch (\RuntimeException) {
            }
        });

        $this->assertSame(['kept', 'outer'], $db->table('items')->orderBy('id')->pluck('name')->all());
        $this->assertSame(0, $db->transactionLevel());
        $this->assertFalse($db->getPdo()->inTransaction());
    }

    private function connection(): Connection
    {
        return DB::connection('sqlite_file');
    }
}
