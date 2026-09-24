<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Tests\Integration\Infrastructure\Worker;

use Amp\NullCancellation;
use Innis\Hubstr\Core\Infrastructure\Persistence\SchemaMigrator;
use Innis\Hubstr\Core\Infrastructure\Persistence\SqliteDatabase;
use Innis\Hubstr\Relay\Infrastructure\Persistence\EventWriteStore;
use Innis\Hubstr\Relay\Infrastructure\Worker\Query\CountByFiltersQuery;
use Innis\Hubstr\Relay\Infrastructure\Worker\Query\FindByFiltersQuery;
use Innis\Hubstr\Relay\Infrastructure\Worker\ReadWorkerTask;
use Innis\Hubstr\Relay\Infrastructure\Worker\WorkerFailure;
use Innis\Hubstr\Relay\Infrastructure\Worker\WriteContext;
use Innis\Hubstr\Relay\Tests\Fake\QueueChannel;
use Innis\Hubstr\Relay\Tests\Fake\ThrowingReadQuery;
use Innis\Hubstr\Relay\Tests\Support\SignedEventFactory;
use Innis\Nostr\Core\Domain\Collection\FilterCollection;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventKind;
use Innis\Nostr\Core\Domain\ValueObject\Identity\KeyPair;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\EventCount;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Filter;
use PDO;
use PHPUnit\Framework\TestCase;

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

    public function testRunAnswersFindByFiltersQuery(): void
    {
        $event = SignedEventFactory::signedEvent($this->keyPair, EventKind::fromInt(EventKind::TEXT_NOTE), 'find me');
        $this->seedStore()->store($event);

        $channel = new QueueChannel([new FindByFiltersQuery(new FilterCollection([Filter::tryFromArray(['kinds' => [1]])]))]);
        new ReadWorkerTask($this->database)->run($channel, new NullCancellation());

        self::assertCount(1, $channel->sent);
        $events = $channel->sent[0];
        self::assertIsArray($events);
        self::assertCount(1, $events);
    }

    public function testRunAnswersConsecutiveQueriesOnOneConnection(): void
    {
        $this->seedStore()->store(SignedEventFactory::signedEvent($this->keyPair, EventKind::fromInt(EventKind::TEXT_NOTE), 'one'));

        $channel = new QueueChannel([
            new FindByFiltersQuery(new FilterCollection([Filter::tryFromArray(['kinds' => [1]])])),
            new CountByFiltersQuery(new FilterCollection([Filter::tryFromArray(['kinds' => [1]])]), 100),
        ]);
        new ReadWorkerTask($this->database)->run($channel, new NullCancellation());

        $found = $channel->sent[0];
        self::assertIsArray($found);
        self::assertCount(1, $found);
        self::assertEquals(EventCount::exact(1), $channel->sent[1]);
    }

    public function testRunReportsFailureAndKeepsProcessingAfterAThrowingQuery(): void
    {
        $this->seedStore()->store(SignedEventFactory::signedEvent($this->keyPair, EventKind::fromInt(EventKind::TEXT_NOTE), 'survivor'));

        $channel = new QueueChannel([
            new ThrowingReadQuery('boom'),
            new CountByFiltersQuery(new FilterCollection([Filter::tryFromArray(['kinds' => [1]])]), 100),
        ]);
        new ReadWorkerTask($this->database)->run($channel, new NullCancellation());

        self::assertInstanceOf(WorkerFailure::class, $channel->sent[0]);
        self::assertStringContainsString('boom', $channel->sent[0]->getMessage());
        self::assertEquals(EventCount::exact(1), $channel->sent[1]);
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
}
