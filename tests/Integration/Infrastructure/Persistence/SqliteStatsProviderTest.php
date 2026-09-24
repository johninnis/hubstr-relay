<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Tests\Integration\Infrastructure\Persistence;

use Innis\Hubstr\Core\Infrastructure\Persistence\SchemaMigrator;
use Innis\Hubstr\Core\Infrastructure\Persistence\SqliteDatabase;
use Innis\Hubstr\Relay\Domain\Enum\StatMetric;
use Innis\Hubstr\Relay\Infrastructure\Persistence\EventWriteStore;
use Innis\Hubstr\Relay\Infrastructure\Persistence\PolicyWriteStore;
use Innis\Hubstr\Relay\Infrastructure\Persistence\SqliteStatsProvider;
use Innis\Hubstr\Relay\Infrastructure\Worker\WriteContext;
use Innis\Hubstr\Relay\Tests\Support\SignedEventFactory;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventKind;
use Innis\Nostr\Core\Domain\ValueObject\Identity\KeyPair;
use PDO;
use PHPUnit\Framework\TestCase;

final class SqliteStatsProviderTest extends TestCase
{
    private PDO $pdo;
    private SqliteStatsProvider $store;
    private EventWriteStore $writeStore;
    private KeyPair $keyPair;

    protected function setUp(): void
    {
        $this->pdo = SqliteDatabase::inMemory()->connect();
        new SchemaMigrator($this->pdo)->migrate(dirname(__DIR__, 4).'/resources/migrations');
        $this->store = new SqliteStatsProvider($this->pdo);
        $this->writeStore = WriteContext::forConnection($this->pdo)->getEventWriteStore();
        $this->keyPair = KeyPair::generate(SignedEventFactory::signer());
    }

    public function testEmptyDatabaseReportsZeroTotals(): void
    {
        $totals = $this->store->getStats();

        self::assertSame(0, $totals->getCounts()->get(StatMetric::Events));
    }

    public function testStoredEventsAreCounted(): void
    {
        $this->writeStore->store(SignedEventFactory::signedEvent($this->keyPair, EventKind::fromInt(EventKind::TEXT_NOTE), 'one'));
        $this->writeStore->store(SignedEventFactory::signedEvent($this->keyPair, EventKind::fromInt(EventKind::TEXT_NOTE), 'two'));

        $totals = $this->store->getStats();

        self::assertSame(2, $totals->getCounts()->get(StatMetric::Events));
    }

    public function testKnownPubkeysCountsDistinctEventAuthors(): void
    {
        $otherKeyPair = KeyPair::generate(SignedEventFactory::signer());
        $this->writeStore->store(SignedEventFactory::signedEvent($this->keyPair, EventKind::fromInt(EventKind::TEXT_NOTE), 'one'));
        $this->writeStore->store(SignedEventFactory::signedEvent($this->keyPair, EventKind::fromInt(EventKind::TEXT_NOTE), 'two'));
        $this->writeStore->store(SignedEventFactory::signedEvent($otherKeyPair, EventKind::fromInt(EventKind::TEXT_NOTE), 'three'));

        $totals = $this->store->getStats();

        self::assertSame(2, $totals->getCounts()->get(StatMetric::KnownPubkeys));
    }

    public function testEventsAreBrokenDownByKind(): void
    {
        $this->writeStore->store(SignedEventFactory::signedEvent($this->keyPair, EventKind::fromInt(EventKind::TEXT_NOTE), 'one'));
        $this->writeStore->store(SignedEventFactory::signedEvent($this->keyPair, EventKind::fromInt(EventKind::TEXT_NOTE), 'two'));
        $this->writeStore->store(SignedEventFactory::signedEvent($this->keyPair, EventKind::fromInt(EventKind::COMMENT), 'three'));

        $totals = $this->store->getStats();

        self::assertSame(
            [['kind' => EventKind::TEXT_NOTE, 'count' => 2], ['kind' => EventKind::COMMENT, 'count' => 1]],
            $totals->getEventsByKind()->toWireArray(),
        );
    }

    public function testEventsAreBrokenDownByTenantIncludingTenantsWithNone(): void
    {
        $quietTenant = KeyPair::generate(SignedEventFactory::signer())->getPublicKey();
        $policyStore = new PolicyWriteStore($this->pdo);
        $policyStore->addTenant($this->keyPair->getPublicKey());
        $policyStore->addTenant($quietTenant);
        $this->writeStore->store(SignedEventFactory::signedEvent($this->keyPair, EventKind::fromInt(EventKind::TEXT_NOTE), 'one'));

        $totals = $this->store->getStats();

        self::assertSame(
            [
                ['pubkey' => $this->keyPair->getPublicKey()->toHex(), 'count' => 1],
                ['pubkey' => $quietTenant->toHex(), 'count' => 0],
            ],
            $totals->getEventsByTenant()->toWireArray(),
        );
    }
}
