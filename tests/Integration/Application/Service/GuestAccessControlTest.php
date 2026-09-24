<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Tests\Integration\Application\Service;

use Innis\Hubstr\Core\Infrastructure\Persistence\SchemaMigrator;
use Innis\Hubstr\Core\Infrastructure\Persistence\SqliteDatabase;
use Innis\Hubstr\Relay\Application\Service\HubstrPolicy;
use Innis\Hubstr\Relay\Domain\ValueObject\GuestPolicy;
use Innis\Hubstr\Relay\Domain\ValueObject\RelayLimits;
use Innis\Hubstr\Relay\Infrastructure\Persistence\EventQueryStore;
use Innis\Hubstr\Relay\Infrastructure\Persistence\PolicyReadStore;
use Innis\Hubstr\Relay\Infrastructure\Persistence\PolicyState;
use Innis\Hubstr\Relay\Infrastructure\Persistence\WriteThroughPolicyManagement;
use Innis\Hubstr\Relay\Infrastructure\Worker\WriteContext;
use Innis\Hubstr\Relay\Infrastructure\Worker\WriteCoordinator;
use Innis\Hubstr\Relay\Tests\Fake\CapturingConnection;
use Innis\Hubstr\Relay\Tests\Fake\DirectWriteChannel;
use Innis\Hubstr\Relay\Tests\Support\SignedEventFactory;
use Innis\Nostr\Core\Domain\Collection\EventCollection;
use Innis\Nostr\Core\Domain\Collection\EventCoordinateCollection;
use Innis\Nostr\Core\Domain\Collection\EventIdCollection;
use Innis\Nostr\Core\Domain\Collection\FilterCollection;
use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventKind;
use Innis\Nostr\Core\Domain\ValueObject\Identity\KeyPair;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\EventCount;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Filter;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\SubscriptionId;
use Innis\Nostr\Core\Domain\ValueObject\Timestamp;
use Innis\Nostr\Core\Infrastructure\Crypto\NativeRandomBytesGenerator;
use Innis\Nostr\Core\Infrastructure\Time\SystemClock;
use Innis\Nostr\Relay\Application\Port\MetricsCollectorInterface;
use Innis\Nostr\Relay\Application\Port\RateLimiterInterface;
use Innis\Nostr\Relay\Application\Port\RelayEventStoreInterface;
use Innis\Nostr\Relay\Application\Service\AuthChallengeIssuer;
use Innis\Nostr\Relay\Application\Service\ClientMessenger;
use Innis\Nostr\Relay\Application\Service\InMemoryAuthenticationRegistry;
use Innis\Nostr\Relay\Application\Service\InMemoryClientRegistry;
use Innis\Nostr\Relay\Application\Service\InMemorySubscriptionRegistry;
use Innis\Nostr\Relay\Application\Service\RateLimitGate;
use Innis\Nostr\Relay\Application\Service\StoredEventStreamer;
use Innis\Nostr\Relay\Application\Service\SubscriptionActivator;
use Innis\Nostr\Relay\Application\Service\SubscriptionAdmission;
use Innis\Nostr\Relay\Application\UseCase\CreateSubscriptionUseCase;
use Innis\Nostr\Relay\Domain\Enum\EventStoreOutcome;
use Innis\Nostr\Relay\Domain\ValueObject\ConnectionInfo;
use Innis\Nostr\Relay\Domain\ValueObject\IpAddress;
use Innis\Nostr\Relay\Infrastructure\Concurrency\AmphpDeferredExecutor;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Revolt\EventLoop;
use RuntimeException;

final class GuestAccessControlTest extends TestCase
{
    private PDO $pdo;
    private KeyPair $tenant;
    private KeyPair $stranger;
    private HubstrPolicy $policy;
    private InMemoryAuthenticationRegistry $authManager;
    private RelayEventStoreInterface $eventStore;
    private InMemorySubscriptionRegistry $subscriptionManager;
    private InMemoryClientRegistry $clientManager;
    private CreateSubscriptionUseCase $useCase;
    private string $strangerEventId;

    protected function setUp(): void
    {
        $this->pdo = SqliteDatabase::inMemory()->connect();
        new SchemaMigrator($this->pdo)->migrate(dirname(__DIR__, 4).'/resources/migrations');

        $this->tenant = KeyPair::generate(SignedEventFactory::signer());
        $this->stranger = KeyPair::generate(SignedEventFactory::signer());

        $writeCoordinator = new WriteCoordinator(new DirectWriteChannel($this->pdo));
        $policyState = new PolicyState(new PolicyReadStore($this->pdo));
        $policyManagement = new WriteThroughPolicyManagement($policyState, $writeCoordinator);
        $policyManagement->addTenant($this->tenant->getPublicKey());

        $writeStore = WriteContext::forConnection($this->pdo)->getEventWriteStore();
        $writeStore->store(SignedEventFactory::signedEvent($this->tenant, EventKind::fromInt(EventKind::TEXT_NOTE), 'tenant note'));
        $writeStore->store(SignedEventFactory::signedEvent($this->tenant, EventKind::fromInt(EventKind::METADATA), '{"name":"tenant"}'));
        $writeStore->store(SignedEventFactory::signedEvent($this->tenant, EventKind::fromInt(EventKind::ENCRYPTED_DIRECT_MESSAGE), 'tenant dm'));
        $strangerNote = SignedEventFactory::signedEvent($this->stranger, EventKind::fromInt(EventKind::TEXT_NOTE), 'stranger note');
        $writeStore->store($strangerNote);
        $writeStore->store(SignedEventFactory::signedEvent($this->stranger, EventKind::fromInt(EventKind::ENCRYPTED_DIRECT_MESSAGE), 'stranger dm'));
        $this->strangerEventId = $strangerNote->getId()->toHex();

        $this->authManager = new InMemoryAuthenticationRegistry(new NativeRandomBytesGenerator());
        $this->policy = new HubstrPolicy($policyState, $this->authManager, RelayLimits::defaults());
        $this->eventStore = $this->directEventStore(new EventQueryStore($this->pdo));
        $this->subscriptionManager = new InMemorySubscriptionRegistry($this->createStub(MetricsCollectorInterface::class), new NullLogger());
        $this->clientManager = new InMemoryClientRegistry($this->createStub(MetricsCollectorInterface::class), new NativeRandomBytesGenerator(), new NullLogger());
        $clientMessenger = new ClientMessenger($this->clientManager);

        $rateLimiter = $this->createStub(RateLimiterInterface::class);
        $rateLimiter->method('tryConsume')->willReturn(true);

        $admission = new SubscriptionAdmission(
            $this->policy,
            new RateLimitGate($rateLimiter, $this->policy),
            $this->subscriptionManager,
        );

        $storedEventStreamer = new StoredEventStreamer(
            $this->eventStore,
            $this->policy,
            $clientMessenger,
            $this->subscriptionManager,
            new SystemClock(),
            new NullLogger(),
        );

        $activator = new SubscriptionActivator(
            $admission,
            $this->subscriptionManager,
            $storedEventStreamer,
            new AmphpDeferredExecutor(),
            new AuthChallengeIssuer($this->authManager),
        );

        $this->useCase = new CreateSubscriptionUseCase(
            $activator,
            new NullLogger(),
        );
    }

    /**
     * @return array<array{0: string, 1: list<array<string, mixed>>, 2: bool}>
     */
    public static function guestScenarios(): array
    {
        $tenant = 'TENANT_PUBKEY';
        $stranger = str_repeat('b', 64);

        return [
            'bare filter' => ['bare', [[]], false],
            'readable kind only' => ['readable-kind', [['kinds' => [1]]], false],
            'non-readable kind' => ['non-readable-kind', [['kinds' => [4]]], true],
            'mixed kinds' => ['mixed-kinds', [['kinds' => [1, 4]]], false],
            'empty kinds' => ['empty-kinds', [['kinds' => []]], true],
            'tenant author' => ['tenant-author', [['authors' => [$tenant]]], false],
            'stranger author' => ['stranger-author', [['authors' => [$stranger]]], true],
            'mixed authors' => ['mixed-authors', [['authors' => [$tenant, $stranger]]], false],
            'empty authors' => ['empty-authors', [['authors' => []]], true],
            'empty ids' => ['empty-ids', [['ids' => []]], true],
            'absent id' => ['absent-id', [['ids' => [str_repeat('a', 64)]]], true],
            'empty tag values' => ['empty-tag', [['#e' => []]], true],
            'multi-filter stranger or tenant' => ['multi', [['authors' => [$stranger]], ['authors' => [$tenant]]], false],
            'specific stranger event id' => ['stranger-id', [['ids' => ['STRANGER_EVENT_ID']]], true],
            'stranger id plus readable kind' => ['stranger-id-kind', [['ids' => ['STRANGER_EVENT_ID'], 'kinds' => [1]]], true],
        ];
    }

    /**
     * @param list<array<string, mixed>> $filters
     */
    #[DataProvider('guestScenarios')]
    public function testGuestNeverReceivesNonTenantOrNonReadableEvents(string $label, array $filters, bool $expectZero): void
    {
        $delivered = $this->deliveredEvents($filters, authenticateTenant: false);

        if ($expectZero) {
            self::assertSame([], $delivered, "{$label}: out-of-scope selector leaked events to a guest");

            return;
        }

        self::assertNotEmpty($delivered, "{$label}: in-scope selector delivered nothing");

        $tenantHex = $this->tenant->getPublicKey()->toHex();
        $readPolicy = GuestPolicy::defaults()->getRead();
        foreach ($delivered as $event) {
            $kind = $event->getKind();
            self::assertSame($tenantHex, $event->getPubkey()->toHex(), "{$label}: guest received a non-tenant author");
            self::assertTrue($readPolicy->getKinds()->contains($kind), "{$label}: guest received a non-readable kind {$kind->toInt()}");
        }
    }

    public function testGuestReceivesTenantReadableEventForBareFilter(): void
    {
        $delivered = $this->deliveredEvents([[]], authenticateTenant: false);

        self::assertNotEmpty($delivered);
        foreach ($delivered as $event) {
            self::assertSame($this->tenant->getPublicKey()->toHex(), $event->getPubkey()->toHex());
        }
    }

    public function testGuestCannotFetchStrangerEventById(): void
    {
        $delivered = $this->deliveredEvents([['ids' => [$this->strangerEventId]]], authenticateTenant: false);

        self::assertSame([], $delivered);
    }

    public function testTenantSeesEverythingIncludingStrangerEvents(): void
    {
        $delivered = $this->deliveredEvents([[]], authenticateTenant: true);

        $authors = array_map(static fn (Event $event) => $event->getPubkey()->toHex(), $delivered);
        self::assertContains($this->stranger->getPublicKey()->toHex(), $authors);
        self::assertCount(5, $delivered);
    }

    public function testTenantCanFetchStrangerEventById(): void
    {
        $delivered = $this->deliveredEvents([['ids' => [$this->strangerEventId]]], authenticateTenant: true);

        self::assertCount(1, $delivered);
        self::assertSame($this->strangerEventId, $delivered[0]->getId()->toHex());
    }

    /**
     * @param list<array<string, mixed>> $rawFilters
     *
     * @return list<Event>
     */
    private function deliveredEvents(array $rawFilters, bool $authenticateTenant): array
    {
        $filters = new FilterCollection(array_map($this->resolveFilter(...), $rawFilters));

        $connection = new CapturingConnection();
        $client = $this->clientManager->registerClient(
            $connection,
            new ConnectionInfo(IpAddress::fromString('127.0.0.1'), 'Test/1.0', Timestamp::now()),
        );

        if ($authenticateTenant) {
            $this->authManager->authenticate($client->getId(), $this->tenant->getPublicKey());
        }

        $subscriptionId = SubscriptionId::tryFromString('sub-1') ?? throw new RuntimeException('Invalid subscription id');
        $this->useCase->execute($client, $subscriptionId, $filters);
        EventLoop::run();

        $events = [];
        foreach ($connection->messages as $message) {
            $decoded = json_decode($message, true);
            if (!is_array($decoded) || 'EVENT' !== ($decoded[0] ?? null)) {
                continue;
            }
            $payload = $decoded[2] ?? null;
            if (!is_array($payload)) {
                continue;
            }
            $events[] = Event::tryFromArray($payload) ?? throw new RuntimeException('Delivered EVENT frame was not a valid event');
        }

        return $events;
    }

    /**
     * @param array<string, mixed> $rawFilter
     */
    private function resolveFilter(array $rawFilter): Filter
    {
        $json = json_encode($rawFilter, JSON_THROW_ON_ERROR);
        $json = str_replace('TENANT_PUBKEY', $this->tenant->getPublicKey()->toHex(), $json);
        $json = str_replace('STRANGER_EVENT_ID', $this->strangerEventId, $json);

        return Filter::tryFromArray((array) json_decode($json, true, flags: JSON_THROW_ON_ERROR)) ?? throw new RuntimeException('Invalid filter');
    }

    private function directEventStore(EventQueryStore $queryStore): RelayEventStoreInterface
    {
        return new class($queryStore) implements RelayEventStoreInterface {
            public function __construct(private readonly EventQueryStore $queryStore)
            {
            }

            public function store(Event $event): EventStoreOutcome
            {
                return EventStoreOutcome::Stored;
            }

            public function findByFilters(FilterCollection $filters, int $limit = 100): EventCollection
            {
                return new EventCollection(array_values(array_filter(array_map(
                    static fn (string $rawEvent) => Event::tryFromJson($rawEvent),
                    $this->queryStore->findRawJsonByFilters($filters),
                ))));
            }

            public function countByFilters(FilterCollection $filters): EventCount
            {
                return $this->queryStore->countByFilters($filters, 1000);
            }

            public function deleteByEventIds(EventIdCollection $eventIds, PublicKey $author): int
            {
                return 0;
            }

            public function deleteByCoordinates(EventCoordinateCollection $coordinates, PublicKey $author): int
            {
                return 0;
            }
        };
    }
}
