<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Tests\Integration\Infrastructure\Persistence;

use Innis\Hubstr\Core\Infrastructure\Persistence\SqliteDatabase;
use Innis\Hubstr\Relay\Infrastructure\Persistence\StatementRunner;
use PDO;
use PHPUnit\Framework\TestCase;

final class StatementRunnerTest extends TestCase
{
    private string $databasePath;
    private PDO $pdo;
    private StatementRunner $runner;

    protected function setUp(): void
    {
        $this->databasePath = (string) tempnam(sys_get_temp_dir(), 'hubstr-relay-statements-');
        $this->pdo = SqliteDatabase::atPath($this->databasePath)->connect();
        $this->pdo->exec('CREATE TABLE widgets (id INTEGER PRIMARY KEY, name TEXT)');
        $this->runner = new StatementRunner($this->pdo);
    }

    protected function tearDown(): void
    {
        foreach ([$this->databasePath, $this->databasePath.'-wal', $this->databasePath.'-shm'] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    public function testExecuteReportsTheNumberOfAffectedRows(): void
    {
        $this->runner->execute('INSERT INTO widgets (name) VALUES (?)', ['alpha']);
        $this->runner->execute('INSERT INTO widgets (name) VALUES (?)', ['alpha']);

        self::assertSame(2, $this->runner->execute('DELETE FROM widgets WHERE name = ?', ['alpha']));
    }

    public function testTheSameSqlRunsAgainWithFreshParameters(): void
    {
        $this->runner->execute('INSERT INTO widgets (name) VALUES (?)', ['alpha']);
        $this->runner->execute('INSERT INTO widgets (name) VALUES (?)', ['beta']);

        $row = $this->runner->selectRow('SELECT COUNT(*) AS total FROM widgets');

        self::assertSame(2, $row['total'] ?? null);
    }

    public function testSelectRowReturnsNullWhenNothingMatches(): void
    {
        self::assertNull($this->runner->selectRow('SELECT name FROM widgets WHERE name = ?', ['absent']));
    }

    public function testSelectRowReturnsTheFirstRowOfAMultiRowResult(): void
    {
        $this->runner->execute('INSERT INTO widgets (name) VALUES (?)', ['alpha']);
        $this->runner->execute('INSERT INTO widgets (name) VALUES (?)', ['beta']);

        $row = $this->runner->selectRow('SELECT name FROM widgets ORDER BY name');

        self::assertSame('alpha', $row['name'] ?? null);
    }

    // Deliberate: this pins the reason no PDOStatement escapes the runner — see ADR-0020
    public function testAMatchedSelectLeavesTheConnectionAbleToCheckpoint(): void
    {
        $this->runner->execute('INSERT INTO widgets (name) VALUES (?)', ['alpha']);
        $this->runner->selectRow('SELECT name FROM widgets WHERE name = ?', ['alpha']);

        self::assertSame(0, $this->checkpointBusyFlag());
    }

    private function checkpointBusyFlag(): int
    {
        $statement = $this->pdo->query('PRAGMA wal_checkpoint(TRUNCATE)');
        self::assertNotFalse($statement);

        $row = $statement->fetch(PDO::FETCH_NUM);
        self::assertIsArray($row);

        $busy = $row[0] ?? null;
        self::assertIsInt($busy);

        return $busy;
    }
}
