<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Tests\Integration\Infrastructure\Worker;

use Amp\NullCancellation;
use Innis\Hubstr\Core\Infrastructure\Persistence\SchemaMigrator;
use Innis\Hubstr\Core\Infrastructure\Persistence\SqliteDatabase;
use Innis\Hubstr\Relay\Infrastructure\Worker\Command\AddTenantCommand;
use Innis\Hubstr\Relay\Infrastructure\Worker\Command\DeleteEventIdsCommand;
use Innis\Hubstr\Relay\Infrastructure\Worker\Command\DeletePubkeyChunkCommand;
use Innis\Hubstr\Relay\Infrastructure\Worker\Command\StoreEventsCommand;
use Innis\Hubstr\Relay\Infrastructure\Worker\WorkerFailure;
use Innis\Hubstr\Relay\Infrastructure\Worker\WriteWorkerTask;
use Innis\Hubstr\Relay\Tests\Fake\QueueChannel;
use Innis\Hubstr\Relay\Tests\Fake\ThrowingWriteCommand;
use Innis\Hubstr\Relay\Tests\Support\SignedEventFactory;
use Innis\Nostr\Core\Domain\Collection\EventCollection;
use Innis\Nostr\Core\Domain\Collection\EventIdCollection;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventKind;
use Innis\Nostr\Core\Domain\ValueObject\Identity\KeyPair;
use Innis\Nostr\Relay\Domain\Enum\EventStoreOutcome;
use PDO;
use PHPUnit\Framework\TestCase;

final class WriteWorkerTaskTest extends TestCase
{
    private string $databasePath;
    private SqliteDatabase $database;
    private PDO $pdo;
    private KeyPair $keyPair;

    protected function setUp(): void
    {
        $this->databasePath = tempnam(sys_get_temp_dir(), 'hubstr-relay-write-');
        $this->database = SqliteDatabase::atPath($this->databasePath);
        $this->pdo = $this->database->connect();
        new SchemaMigrator($this->pdo)->migrate(dirname(__DIR__, 4).'/resources/migrations');
        $this->keyPair = KeyPair::generate(SignedEventFactory::signer());
    }

    protected function tearDown(): void
    {
        unset($this->pdo);
        @unlink($this->databasePath);
    }

    public function testRunStoresEventFromStoreCommand(): void
    {
        $event = SignedEventFactory::signedEvent($this->keyPair, EventKind::fromInt(EventKind::TEXT_NOTE), 'persist me');
        $channel = new QueueChannel([new StoreEventsCommand(new EventCollection([$event]))]);

        new WriteWorkerTask($this->database)->run($channel, new NullCancellation());

        self::assertSame([[EventStoreOutcome::Stored]], $channel->sent);
    }

    public function testRunReportsDuplicateForRepeatedEvent(): void
    {
        $event = SignedEventFactory::signedEvent($this->keyPair, EventKind::fromInt(EventKind::TEXT_NOTE), 'once');
        $channel = new QueueChannel([
            new StoreEventsCommand(new EventCollection([$event])),
            new StoreEventsCommand(new EventCollection([$event])),
        ]);

        new WriteWorkerTask($this->database)->run($channel, new NullCancellation());

        self::assertSame(
            [[EventStoreOutcome::Stored], [EventStoreOutcome::Duplicate]],
            $channel->sent,
        );
    }

    public function testRunDeletesEventsByIdForOwnAuthor(): void
    {
        $event = SignedEventFactory::signedEvent($this->keyPair, EventKind::fromInt(EventKind::TEXT_NOTE), 'deletable');
        $channel = new QueueChannel([
            new StoreEventsCommand(new EventCollection([$event])),
            new DeleteEventIdsCommand(new EventIdCollection([$event->getId()]), $event->getPubkey()),
        ]);

        new WriteWorkerTask($this->database)->run($channel, new NullCancellation());

        self::assertSame([[EventStoreOutcome::Stored], 1], $channel->sent);
    }

    public function testRunDeletesPubkeyChunk(): void
    {
        $event = SignedEventFactory::signedEvent($this->keyPair, EventKind::fromInt(EventKind::TEXT_NOTE), 'spam');
        $channel = new QueueChannel([
            new StoreEventsCommand(new EventCollection([$event])),
            new DeletePubkeyChunkCommand($event->getPubkey(), 100),
        ]);

        new WriteWorkerTask($this->database)->run($channel, new NullCancellation());

        self::assertSame([[EventStoreOutcome::Stored], 1], $channel->sent);
    }

    public function testRunExecutesPolicyCommand(): void
    {
        $channel = new QueueChannel([new AddTenantCommand(SignedEventFactory::pubkey('aa'))]);

        new WriteWorkerTask($this->database)->run($channel, new NullCancellation());

        self::assertSame([null], $channel->sent);
    }

    public function testRunReportsFailureAndKeepsProcessingAfterAThrowingCommand(): void
    {
        $event = SignedEventFactory::signedEvent($this->keyPair, EventKind::fromInt(EventKind::TEXT_NOTE), 'after failure');
        $channel = new QueueChannel([
            new ThrowingWriteCommand('boom'),
            new StoreEventsCommand(new EventCollection([$event])),
        ]);

        new WriteWorkerTask($this->database)->run($channel, new NullCancellation());

        self::assertInstanceOf(WorkerFailure::class, $channel->sent[0]);
        self::assertStringContainsString('boom', $channel->sent[0]->getMessage());
        self::assertSame([EventStoreOutcome::Stored], $channel->sent[1]);
    }

    public function testRunStopsWhenChannelIsExhausted(): void
    {
        $channel = new QueueChannel();

        new WriteWorkerTask($this->database)->run($channel, new NullCancellation());

        self::assertSame([], $channel->sent);
    }

    public function testTaskIsSerialisable(): void
    {
        $task = new WriteWorkerTask($this->database);

        self::assertInstanceOf(WriteWorkerTask::class, unserialize(serialize($task)));
    }
}
