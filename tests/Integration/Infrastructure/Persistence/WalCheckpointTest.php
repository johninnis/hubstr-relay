<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Tests\Integration\Infrastructure\Persistence;

use Innis\Hubstr\Core\Infrastructure\Persistence\SchemaMigrator;
use Innis\Hubstr\Core\Infrastructure\Persistence\SqliteDatabase;
use Innis\Hubstr\Relay\Infrastructure\Persistence\EventWriteStore;
use Innis\Hubstr\Relay\Infrastructure\Worker\WriteContext;
use Innis\Hubstr\Relay\Tests\Support\SignedEventFactory;
use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventKind;
use Innis\Nostr\Core\Domain\ValueObject\Identity\KeyPair;
use PDO;
use PHPUnit\Framework\TestCase;

// Deliberate: a writing connection that cannot checkpoint grows its WAL without bound — see ADR-0020
final class WalCheckpointTest extends TestCase
{
    private string $databasePath;
    private PDO $pdo;
    private EventWriteStore $writeStore;
    private KeyPair $keyPair;

    protected function setUp(): void
    {
        $this->databasePath = (string) tempnam(sys_get_temp_dir(), 'hubstr-relay-wal-');
        $this->pdo = SqliteDatabase::atPath($this->databasePath)->connect();
        new SchemaMigrator($this->pdo)->migrate(dirname(__DIR__, 4).'/resources/migrations');

        $this->writeStore = WriteContext::forConnection($this->pdo)->getEventWriteStore();
        $this->keyPair = KeyPair::generate(SignedEventFactory::signer());
    }

    protected function tearDown(): void
    {
        foreach ([$this->databasePath, $this->databasePath.'-wal', $this->databasePath.'-shm'] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    public function testTheWriteConnectionCheckpointsAfterStoringAnEvent(): void
    {
        $this->writeStore->store($this->note('first'));

        self::assertSame(0, $this->checkpointBusyFlag());
    }

    public function testTheWriteConnectionCheckpointsAfterRejectingADuplicate(): void
    {
        $event = $this->note('twice');
        $this->writeStore->store($event);
        $this->writeStore->store($event);

        self::assertSame(0, $this->checkpointBusyFlag());
    }

    public function testTheWriteConnectionCheckpointsAfterReplacingAnEvent(): void
    {
        $this->writeStore->store($this->metadata('original'));
        $this->writeStore->store($this->metadata('replacement'));

        self::assertSame(0, $this->checkpointBusyFlag());
    }

    public function testCheckpointingTruncatesTheWriteAheadLog(): void
    {
        foreach (range(1, 20) as $index) {
            $this->writeStore->store($this->note("note {$index}"));
        }

        $this->checkpointBusyFlag();
        clearstatcache(true, $this->databasePath.'-wal');

        self::assertSame(0, filesize($this->databasePath.'-wal'));
    }

    private function note(string $content): Event
    {
        return SignedEventFactory::signedEvent($this->keyPair, EventKind::fromInt(EventKind::TEXT_NOTE), $content);
    }

    private function metadata(string $content): Event
    {
        return SignedEventFactory::signedEvent($this->keyPair, EventKind::fromInt(EventKind::METADATA), $content);
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
