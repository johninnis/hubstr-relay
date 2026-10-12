<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Tests\Integration\Infrastructure\Worker;

use Amp\NullCancellation;
use Innis\Hubstr\Core\Infrastructure\Persistence\SchemaMigrator;
use Innis\Hubstr\Core\Infrastructure\Persistence\SqliteDatabase;
use Innis\Hubstr\Relay\Infrastructure\Persistence\EventWriteStore;
use Innis\Hubstr\Relay\Infrastructure\Worker\Query\CountByFiltersQuery;
use Innis\Hubstr\Relay\Infrastructure\Worker\Query\FindByFiltersQuery;
use Innis\Hubstr\Relay\Infrastructure\Worker\ReadQueryInterface;
use Innis\Hubstr\Relay\Infrastructure\Worker\ReadWorkerTask;
use Innis\Hubstr\Relay\Infrastructure\Worker\WorkerFailure;
use Innis\Hubstr\Relay\Infrastructure\Worker\WriteContext;
use Innis\Hubstr\Relay\Tests\Fake\QueueChannel;
use Innis\Hubstr\Relay\Tests\Fake\ThrowingReadQuery;
use Innis\Hubstr\Relay\Tests\Support\ActingKeysMother;
use Innis\Hubstr\Relay\Tests\Support\SignedEventFactory;
use Innis\Nostr\Core\Domain\Collection\FilterCollection;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventKind;
use Innis\Nostr\Core\Domain\ValueObject\Identity\KeyPair;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\EventCount;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Filter;
use Innis\Nostr\Relay\Domain\Collection\StoredEventCollection;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ReadWorkerTaskTest extends TestCase
{
    private string $databasePath;
    private SqliteDatabase $database;
    private PDO $pdo;
    private KeyPair $keyPair;

    protected function setUp(): void
    {
        $this->databasePath = tempnam(sys_get_temp_dir(), 'hubstr-relay-read-');
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

    public function testRunAnswersAFindQueryWithOneReply(): void
    {
        $this->seedStore()->store(SignedEventFactory::signedEvent($this->keyPair, EventKind::fromInt(EventKind::TEXT_NOTE), 'find me'));

        self::assertCount(1, $this->repliesTo(self::findTextNotes()));
    }

    public function testRunAnswersAFindQueryWithStoredEvents(): void
    {
        $this->seedStore()->store(SignedEventFactory::signedEvent($this->keyPair, EventKind::fromInt(EventKind::TEXT_NOTE), 'find me'));

        self::assertInstanceOf(StoredEventCollection::class, $this->repliesTo(self::findTextNotes())[0]);
    }

    public function testRunAnswersAFindQueryWithTheMatchingEvents(): void
    {
        $this->seedStore()->store(SignedEventFactory::signedEvent($this->keyPair, EventKind::fromInt(EventKind::TEXT_NOTE), 'find me'));

        self::assertCount(1, self::storedEvents($this->repliesTo(self::findTextNotes())[0]));
    }

    public function testRunAnswersTheFirstOfConsecutiveQueriesOnOneConnection(): void
    {
        $this->seedStore()->store(SignedEventFactory::signedEvent($this->keyPair, EventKind::fromInt(EventKind::TEXT_NOTE), 'one'));

        self::assertCount(1, self::storedEvents($this->repliesTo(self::findTextNotes(), self::countTextNotes())[0]));
    }

    public function testRunAnswersTheSecondOfConsecutiveQueriesOnOneConnection(): void
    {
        $this->seedStore()->store(SignedEventFactory::signedEvent($this->keyPair, EventKind::fromInt(EventKind::TEXT_NOTE), 'one'));

        self::assertEquals(EventCount::exact(1), $this->repliesTo(self::findTextNotes(), self::countTextNotes())[1]);
    }

    public function testRunReportsAThrowingQueryAsAWorkerFailure(): void
    {
        self::assertInstanceOf(WorkerFailure::class, $this->repliesTo(new ThrowingReadQuery('boom'), self::countTextNotes())[0]);
    }

    public function testRunReportsTheMessageOfAThrowingQuery(): void
    {
        self::assertStringContainsString('boom', self::workerFailure($this->repliesTo(new ThrowingReadQuery('boom'), self::countTextNotes())[0])->getMessage());
    }

    public function testRunKeepsProcessingAfterAThrowingQuery(): void
    {
        $this->seedStore()->store(SignedEventFactory::signedEvent($this->keyPair, EventKind::fromInt(EventKind::TEXT_NOTE), 'survivor'));

        self::assertEquals(EventCount::exact(1), $this->repliesTo(new ThrowingReadQuery('boom'), self::countTextNotes())[1]);
    }

    public function testRunStopsWhenChannelIsExhausted(): void
    {
        $channel = new QueueChannel();

        new ReadWorkerTask($this->database)->run($channel, new NullCancellation());

        self::assertSame([], $channel->sent);
    }

    public function testTaskIsSerialisable(): void
    {
        $task = new ReadWorkerTask($this->database);

        self::assertInstanceOf(ReadWorkerTask::class, unserialize(serialize($task)));
    }

    private function seedStore(): EventWriteStore
    {
        return WriteContext::forConnection($this->pdo)->getEventWriteStore();
    }

    /**
     * @return list<mixed>
     */
    private function repliesTo(ReadQueryInterface ...$queries): array
    {
        $channel = new QueueChannel(array_values($queries));
        new ReadWorkerTask($this->database)->run($channel, new NullCancellation());

        return $channel->sent;
    }

    private static function findTextNotes(): FindByFiltersQuery
    {
        return new FindByFiltersQuery(new FilterCollection([Filter::tryFromArray(['kinds' => [1]])]), ActingKeysMother::anonymous());
    }

    private static function countTextNotes(): CountByFiltersQuery
    {
        return new CountByFiltersQuery(new FilterCollection([Filter::tryFromArray(['kinds' => [1]])]), ActingKeysMother::anonymous(), 100);
    }

    private static function storedEvents(mixed $reply): StoredEventCollection
    {
        return $reply instanceof StoredEventCollection ? $reply : throw new RuntimeException(sprintf('The worker replied %s, not stored events', get_debug_type($reply)));
    }

    private static function workerFailure(mixed $reply): WorkerFailure
    {
        return $reply instanceof WorkerFailure ? $reply : throw new RuntimeException(sprintf('The worker replied %s, not a failure', get_debug_type($reply)));
    }
}
