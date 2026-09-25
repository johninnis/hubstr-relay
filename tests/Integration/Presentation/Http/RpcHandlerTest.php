<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Tests\Integration\Presentation\Http;

use Amp\Http\HttpStatus;
use Amp\Http\Server\Driver\Client;
use Amp\Http\Server\Request;
use Amp\Http\Server\Response;
use Innis\Hubstr\Core\Infrastructure\Persistence\SchemaMigrator;
use Innis\Hubstr\Core\Infrastructure\Persistence\SqliteDatabase;
use Innis\Hubstr\Relay\Application\Collection\ActiveSubscriptionSnapshotCollection;
use Innis\Hubstr\Relay\Application\Collection\ClientSnapshotCollection;
use Innis\Hubstr\Relay\Application\Collection\SubscriptionSnapshotCollection;
use Innis\Hubstr\Relay\Application\DTO\ActiveSubscriptionSnapshot;
use Innis\Hubstr\Relay\Application\DTO\ClientSnapshot;
use Innis\Hubstr\Relay\Application\DTO\SubscriptionSnapshot;
use Innis\Hubstr\Relay\Application\Port\RelayStateInterface;
use Innis\Hubstr\Relay\Application\Service\TenantAuthenticator;
use Innis\Hubstr\Relay\Application\UseCase\BanUseCase;
use Innis\Hubstr\Relay\Application\UseCase\ChangeRelayMetadataUseCase;
use Innis\Hubstr\Relay\Application\UseCase\RemoveTenantUseCase;
use Innis\Hubstr\Relay\Application\UseCase\UpdateRateLimitsUseCase;
use Innis\Hubstr\Relay\Domain\ValueObject\BlockedIp;
use Innis\Hubstr\Relay\Infrastructure\Auth\InMemoryNip98ReplayGuard;
use Innis\Hubstr\Relay\Infrastructure\Persistence\EventWriteStore;
use Innis\Hubstr\Relay\Infrastructure\Persistence\PolicyReadStore;
use Innis\Hubstr\Relay\Infrastructure\Persistence\PolicyState;
use Innis\Hubstr\Relay\Infrastructure\Persistence\SqliteExploreQuery;
use Innis\Hubstr\Relay\Infrastructure\Persistence\SqliteStatsProvider;
use Innis\Hubstr\Relay\Infrastructure\Persistence\SqliteWebOfTrustQuery;
use Innis\Hubstr\Relay\Infrastructure\Persistence\WriteThroughPolicyManagement;
use Innis\Hubstr\Relay\Infrastructure\Worker\WriteContext;
use Innis\Hubstr\Relay\Infrastructure\Worker\WriteCoordinator;
use Innis\Hubstr\Relay\Presentation\Http\Rpc\BlockingRpcHandler;
use Innis\Hubstr\Relay\Presentation\Http\Rpc\InsightsRpcHandler;
use Innis\Hubstr\Relay\Presentation\Http\Rpc\RelayConfigRpcHandler;
use Innis\Hubstr\Relay\Presentation\Http\Rpc\RelayStateRpcHandler;
use Innis\Hubstr\Relay\Presentation\Http\Rpc\TenancyRpcHandler;
use Innis\Hubstr\Relay\Presentation\Http\RpcHandler;
use Innis\Hubstr\Relay\Tests\Fake\DirectWriteChannel;
use Innis\Nostr\Core\Application\Service\Nip98Validator;
use Innis\Nostr\Core\Domain\Collection\FilterCollection;
use Innis\Nostr\Core\Domain\Collection\TagCollection;
use Innis\Nostr\Core\Domain\Enum\SubscriptionState;
use Innis\Nostr\Core\Domain\Service\SignatureServiceInterface;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventContent;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventKind;
use Innis\Nostr\Core\Domain\ValueObject\Identity\KeyPair;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\RelayUrl;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Rumour;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\SubscriptionId;
use Innis\Nostr\Core\Domain\ValueObject\Tag\Tag;
use Innis\Nostr\Core\Domain\ValueObject\Timestamp;
use Innis\Nostr\Core\Infrastructure\Crypto\Secp256k1Signer;
use Innis\Nostr\Core\Infrastructure\Time\SystemClock;
use Innis\Nostr\Relay\Domain\Entity\RelayClient;
use Innis\Nostr\Relay\Domain\ValueObject\ClientId;
use Innis\Nostr\Relay\Domain\ValueObject\ConnectionInfo;
use Innis\Nostr\Relay\Domain\ValueObject\IpAddress;
use Innis\Nostr\Relay\Domain\ValueObject\SessionCounters;
use InvalidArgumentException;
use League\Uri\Http;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function Amp\ByteStream\buffer;

final class RpcHandlerTest extends TestCase
{
    private PDO $pdo;
    private PolicyState $policyState;
    private WriteThroughPolicyManagement $policyManagement;
    private EventWriteStore $eventWriteStore;
    private RpcHandler $handler;
    private KeyPair $tenantKeyPair;
    private SignatureServiceInterface $sigService;

    private function signatureService(): SignatureServiceInterface
    {
        return $this->sigService ??= Secp256k1Signer::create();
    }

    protected function setUp(): void
    {
        $this->pdo = SqliteDatabase::inMemory()->connect();
        new SchemaMigrator($this->pdo)->migrate(dirname(__DIR__, 4).'/resources/migrations');

        $this->tenantKeyPair = KeyPair::generate($this->signatureService());
        $writeCoordinator = new WriteCoordinator(new DirectWriteChannel($this->pdo));
        $this->policyState = new PolicyState(new PolicyReadStore($this->pdo));
        $this->policyManagement = new WriteThroughPolicyManagement($this->policyState, $writeCoordinator);
        $this->policyManagement->addTenant($this->tenantKeyPair->getPublicKey());

        $relayUrl = RelayUrl::tryFromString('wss://relay.example.com') ?? throw new RuntimeException('Invalid URL');
        $nip98Validator = new Nip98Validator($this->signatureService(), new InMemoryNip98ReplayGuard(new SystemClock()), new SystemClock());
        $this->eventWriteStore = WriteContext::forConnection($this->pdo)->getEventWriteStore();
        $exploreQuery = new SqliteExploreQuery($this->pdo);
        $webOfTrustQuery = new SqliteWebOfTrustQuery($this->pdo);
        $this->handler = new RpcHandler(
            new TenantAuthenticator($nip98Validator, $relayUrl, $this->policyState),
            [
                new TenancyRpcHandler($this->policyState, $this->policyManagement, new RemoveTenantUseCase($this->policyState, $this->policyManagement)),
                new BlockingRpcHandler($this->policyState, $this->policyManagement, new BanUseCase($this->policyState, $this->policyManagement, $writeCoordinator)),
                new RelayConfigRpcHandler($this->policyState, new ChangeRelayMetadataUseCase($this->policyState, $this->policyManagement), new UpdateRateLimitsUseCase($this->policyState, $this->policyManagement)),
                new RelayStateRpcHandler($this->createStub(RelayStateInterface::class)),
                new InsightsRpcHandler(new SqliteStatsProvider($this->pdo), $exploreQuery, $webOfTrustQuery),
            ],
        );
    }

    public function testReturnsNullForNonRpcRequest(): void
    {
        $request = $this->request('GET', [], '');

        $this->assertNull($this->handler->handleRequest($request));
    }

    public function testRejectsDuplicateMethodRegistration(): void
    {
        $writeCoordinator = new WriteCoordinator(new DirectWriteChannel($this->pdo));
        $tenancyHandler = new TenancyRpcHandler($this->policyState, $this->policyManagement, new RemoveTenantUseCase($this->policyState, $this->policyManagement));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('duplicate RPC method');

        new RpcHandler(
            new TenantAuthenticator(
                new Nip98Validator($this->signatureService(), new InMemoryNip98ReplayGuard(new SystemClock()), new SystemClock()),
                RelayUrl::tryFromString('wss://relay.example.com') ?? throw new RuntimeException('Invalid URL'),
                $this->policyState,
            ),
            [$tenancyHandler, $tenancyHandler],
        );
    }

    public function testRejectsRequestWithoutAuth(): void
    {
        $request = $this->createRpcRequest('{"method":"supportedmethods"}');

        $response = $this->handler->handleRequest($request);

        $this->assertNotNull($response);
        $this->assertSame(HttpStatus::UNAUTHORIZED, $response->getStatus());
    }

    public function testRejectsNonTenantPubkey(): void
    {
        $nonTenant = KeyPair::generate($this->signatureService());
        $body = '{"method":"supportedmethods"}';
        $authHeader = $this->buildAuthHeaderWithKey($nonTenant, $body);

        $request = $this->request('POST', ['content-type' => 'application/nostr+json+rpc', 'authorization' => $authHeader], $body);

        $response = $this->handler->handleRequest($request);

        $this->assertNotNull($response);
        $this->assertSame(HttpStatus::FORBIDDEN, $response->getStatus());
        $this->assertStringContainsString('not a relay tenant', buffer($response->getBody()));
    }

    public function testAJsonBodyThatIsNotAnEnvelopeIsABadRequest(): void
    {
        $response = $this->authenticatedRpc('{"params":["abcd"]}');

        $this->assertSame(HttpStatus::BAD_REQUEST, $response->getStatus());
        $this->assertStringContainsString('malformed request body', self::str($this->decodeResponse($response)['error']));
    }

    public function testAJsonListBodyIsABadRequest(): void
    {
        $response = $this->authenticatedRpc('["supportedmethods"]');

        $this->assertSame(HttpStatus::BAD_REQUEST, $response->getStatus());
        $this->assertArrayHasKey('error', $this->decodeResponse($response));
    }

    public function testNonListParamsAreABadRequest(): void
    {
        $response = $this->authenticatedRpc('{"method":"banpubkey","params":{"pubkey":"abcd"}}');

        $this->assertSame(HttpStatus::BAD_REQUEST, $response->getStatus());
    }

    public function testTheContentTypeIsMatchedCaseInsensitively(): void
    {
        $body = '{"method":"supportedmethods"}';
        $request = $this->request(
            'POST',
            ['content-type' => 'Application/Nostr+JSON+RPC', 'authorization' => $this->buildAuthHeader($body)],
            $body,
        );

        $response = $this->handler->handleRequest($request);

        $this->assertNotNull($response);
        $this->assertSame(HttpStatus::OK, $response->getStatus());
    }

    public function testAResponseCarriesTheRpcMediaType(): void
    {
        $response = $this->authenticatedRpc('{"method":"supportedmethods"}');

        $this->assertSame('application/nostr+json+rpc', $response->getHeader('content-type'));
    }

    public function testSupportedMethodsReturnsAllMethods(): void
    {
        $response = $this->authenticatedRpc('{"method":"supportedmethods"}');

        $data = $this->decodeResponse($response);
        $this->assertArrayHasKey('result', $data);
        $this->assertContains('allowpubkey', $data['result']);
        $this->assertContains('banword', $data['result']);
        $this->assertContains('getstats', $data['result']);
    }

    public function testListAllowedPubkeys(): void
    {
        $response = $this->authenticatedRpc('{"method":"listallowedpubkeys"}');

        $data = $this->decodeResponse($response);
        $this->assertSame([['pubkey' => $this->tenantKeyPair->getPublicKey()->toHex()]], $data['result']);
    }

    public function testAllowAndUnallowPubkey(): void
    {
        $newPk = KeyPair::generate($this->signatureService())->getPublicKey()->toHex();

        $this->authenticatedRpc(json_encode(['method' => 'allowpubkey', 'params' => [$newPk]], JSON_THROW_ON_ERROR));

        $response = $this->authenticatedRpc('{"method":"listallowedpubkeys"}');
        $data = $this->decodeResponse($response);
        $this->assertCount(2, self::arr($data['result']));

        $this->authenticatedRpc(json_encode(['method' => 'unallowpubkey', 'params' => [$newPk]], JSON_THROW_ON_ERROR));

        $response = $this->authenticatedRpc('{"method":"listallowedpubkeys"}');
        $data = $this->decodeResponse($response);
        $this->assertCount(1, self::arr($data['result']));
    }

    public function testCannotRemoveLastTenant(): void
    {
        $body = json_encode(['method' => 'unallowpubkey', 'params' => [$this->tenantKeyPair->getPublicKey()->toHex()]], JSON_THROW_ON_ERROR);
        $response = $this->authenticatedRpc($body);
        $data = $this->decodeResponse($response);

        $this->assertArrayHasKey('error', $data);
    }

    public function testBanAndUnbanWord(): void
    {
        $this->authenticatedRpc(json_encode(['method' => 'banword', 'params' => ['spam']], JSON_THROW_ON_ERROR));

        $response = $this->authenticatedRpc('{"method":"listbannedwords"}');
        $data = $this->decodeResponse($response);
        $this->assertSame(['spam'], $data['result']);

        $this->authenticatedRpc(json_encode(['method' => 'unbanword', 'params' => ['spam']], JSON_THROW_ON_ERROR));

        $response = $this->authenticatedRpc('{"method":"listbannedwords"}');
        $data = $this->decodeResponse($response);
        $this->assertSame([], $data['result']);
    }

    public function testBanHashtagStoresAndListsItLowercased(): void
    {
        $this->authenticatedRpc(json_encode(['method' => 'banhashtag', 'params' => ['NSFW']], JSON_THROW_ON_ERROR));

        $listed = $this->decodeResponse($this->authenticatedRpc('{"method":"listbannedhashtags"}'));
        $this->assertSame(['nsfw'], $listed['result']);

        $this->authenticatedRpc(json_encode(['method' => 'unbanhashtag', 'params' => ['nsfw']], JSON_THROW_ON_ERROR));

        $listed = $this->decodeResponse($this->authenticatedRpc('{"method":"listbannedhashtags"}'));
        $this->assertSame([], $listed['result']);
    }

    public function testRejectsBanHashtagWithAnEmptyValue(): void
    {
        $response = $this->authenticatedRpc('{"method":"banhashtag","params":[""]}');

        $this->assertSame(HttpStatus::BAD_REQUEST, $response->getStatus());
    }

    public function testRejectsBanWordWithoutParam(): void
    {
        $response = $this->authenticatedRpc('{"method":"banword","params":[]}');

        $this->assertSame(HttpStatus::BAD_REQUEST, $response->getStatus());

        $listed = $this->decodeResponse($this->authenticatedRpc('{"method":"listbannedwords"}'));
        $this->assertSame([], $listed['result']);
    }

    public function testRejectsBlockIpWithEmptyAddress(): void
    {
        $response = $this->authenticatedRpc(json_encode(['method' => 'blockip', 'params' => ['', 'spam']], JSON_THROW_ON_ERROR));

        $this->assertSame(HttpStatus::BAD_REQUEST, $response->getStatus());

        $listed = $this->decodeResponse($this->authenticatedRpc('{"method":"listblockedips"}'));
        $this->assertCount(0, self::arr($listed['result']));
    }

    public function testSetAndGetGuestPolicy(): void
    {
        $policy = ['read' => ['kinds' => [1, 7], 'from_tenants_only' => false], 'write' => ['kinds' => [7], 'tagged_to_tenant' => false]];
        $body = json_encode(['method' => 'setguestpolicy', 'params' => [$policy]], JSON_THROW_ON_ERROR);
        $this->authenticatedRpc($body);

        $response = $this->authenticatedRpc('{"method":"getguestpolicy"}');
        $data = $this->decodeResponse($response);

        $read = self::arr(self::arr($data['result'])['read']);
        $this->assertSame([1, 7], $read['kinds']);
        $this->assertFalse($read['from_tenants_only']);
    }

    public function testSetAndGetGuestPolicyCarriesTheTagPrefixRule(): void
    {
        $policy = [
            'read' => ['kinds' => [1111], 'from_tenants_only' => false],
            'write' => [
                'kinds' => [1111],
                'tagged_to_tenant' => false,
                'tag_prefixes' => [
                    ['tag' => 'I', 'prefixes' => ['https://www.example.com/']],
                    ['tag' => 'i', 'prefixes' => ['https://www.example.com/']],
                ],
            ],
        ];
        $this->authenticatedRpc(json_encode(['method' => 'setguestpolicy', 'params' => [$policy]], JSON_THROW_ON_ERROR));

        $data = $this->decodeResponse($this->authenticatedRpc('{"method":"getguestpolicy"}'));

        $this->assertSame(
            [
                ['tag' => 'I', 'prefixes' => ['https://www.example.com/']],
                ['tag' => 'i', 'prefixes' => ['https://www.example.com/']],
            ],
            self::arr(self::arr(self::arr($data['result'])['write'])['tag_prefixes']),
        );
    }

    public function testAnExplicitNullClearsTheTagPrefixRule(): void
    {
        $withRule = [
            'read' => ['kinds' => [1111], 'from_tenants_only' => false],
            'write' => [
                'kinds' => [1111],
                'tagged_to_tenant' => false,
                'tag_prefixes' => [['tag' => 'I', 'prefixes' => ['https://www.example.com/']]],
            ],
        ];
        $this->authenticatedRpc(json_encode(['method' => 'setguestpolicy', 'params' => [$withRule]], JSON_THROW_ON_ERROR));

        $cleared = $withRule;
        $cleared['write']['tag_prefixes'] = null;
        $response = $this->authenticatedRpc(json_encode(['method' => 'setguestpolicy', 'params' => [$cleared]], JSON_THROW_ON_ERROR));

        $this->assertSame(HttpStatus::OK, $response->getStatus());

        $data = $this->decodeResponse($this->authenticatedRpc('{"method":"getguestpolicy"}'));
        $this->assertSame([], self::arr(self::arr($data['result'])['write'])['tag_prefixes']);
    }

    public function testRejectsAGuestPolicyWhoseTagPrefixRuleDoesNotParse(): void
    {
        $policy = [
            'read' => ['kinds' => [1111], 'from_tenants_only' => false],
            'write' => ['kinds' => [1111], 'tagged_to_tenant' => false, 'tag_prefixes' => [['tag' => 'I', 'prefixes' => []]]],
        ];
        $body = json_encode(['method' => 'setguestpolicy', 'params' => [$policy]], JSON_THROW_ON_ERROR);

        $response = $this->authenticatedRpc($body);

        $this->assertSame(HttpStatus::BAD_REQUEST, $response->getStatus());

        $data = $this->decodeResponse($this->authenticatedRpc('{"method":"getguestpolicy"}'));
        $this->assertTrue(
            self::arr(self::arr($data['result'])['read'])['from_tenants_only'],
            'A refused submission must leave the stored policy untouched, not half-applied.',
        );
    }

    public function testSetAndGetRateLimits(): void
    {
        $limits = ['events_per_minute' => 200, 'subscriptions_per_minute' => 50];
        $body = json_encode(['method' => 'setratelimits', 'params' => [$limits]], JSON_THROW_ON_ERROR);
        $this->authenticatedRpc($body);

        $response = $this->authenticatedRpc('{"method":"getratelimits"}');
        $data = $this->decodeResponse($response);

        $result = self::arr($data['result']);
        $this->assertSame(200, $result['events_per_minute']);
        $this->assertSame(50, $result['subscriptions_per_minute']);
    }

    public function testChangeRelayName(): void
    {
        $body = json_encode(['method' => 'changerelayname', 'params' => ['My Custom Relay']], JSON_THROW_ON_ERROR);
        $this->authenticatedRpc($body);

        $this->assertSame('My Custom Relay', $this->policyState->getMetadata()->getName());
    }

    public function testRejectsAnOverlongBlockReasonAndStoresNothing(): void
    {
        $response = $this->authenticatedRpc(json_encode(['method' => 'blockip', 'params' => ['1.2.3.4', str_repeat('a', BlockedIp::MAX_REASON_LENGTH + 1)]], JSON_THROW_ON_ERROR));

        $this->assertSame(HttpStatus::BAD_REQUEST, $response->getStatus());

        $listed = $this->decodeResponse($this->authenticatedRpc('{"method":"listblockedips"}'));
        $this->assertCount(0, self::arr($listed['result']));
    }

    public function testBlockAndUnblockIp(): void
    {
        $this->authenticatedRpc(json_encode(['method' => 'blockip', 'params' => ['1.2.3.4', 'spam']], JSON_THROW_ON_ERROR));

        $response = $this->authenticatedRpc('{"method":"listblockedips"}');
        $data = $this->decodeResponse($response);
        $this->assertCount(1, self::arr($data['result']));
        $this->assertSame('1.2.3.4', self::arr(self::arr($data['result'])[0])['ip']);

        $this->authenticatedRpc(json_encode(['method' => 'unblockip', 'params' => ['1.2.3.4']], JSON_THROW_ON_ERROR));

        $response = $this->authenticatedRpc('{"method":"listblockedips"}');
        $data = $this->decodeResponse($response);
        $this->assertCount(0, self::arr($data['result']));
    }

    public function testGetStats(): void
    {
        $response = $this->authenticatedRpc('{"method":"getstats"}');
        $data = $this->decodeResponse($response);

        $result = self::arr($data['result']);
        $this->assertArrayHasKey('events', $result);
        $this->assertArrayHasKey('events_by_kind', $result);
    }

    public function testListConnectionsReturnsClientData(): void
    {
        $relayState = $this->createStub(RelayStateInterface::class);
        $relayState->method('getConnectedClients')->willReturn(new ClientSnapshotCollection([
            new ClientSnapshot(
                self::client(),
                new SessionCounters(0, 0, 0),
                new SubscriptionSnapshotCollection(),
            ),
        ]));
        $this->handler = $this->createHandlerWithRelayState($relayState);

        $response = $this->authenticatedRpc('{"method":"listconnections"}');
        $data = $this->decodeResponse($response);

        $this->assertCount(1, self::arr($data['result']));
        $this->assertSame('192.168.1.1', self::arr(self::arr($data['result'])[0])['ip']);
    }

    public function testListSubscriptionsReturnsSubscriptionData(): void
    {
        $relayState = $this->createStub(RelayStateInterface::class);
        $relayState->method('getActiveSubscriptions')->willReturn(new ActiveSubscriptionSnapshotCollection([
            new ActiveSubscriptionSnapshot(self::client(), new SubscriptionSnapshot(
                SubscriptionId::tryFromString('sub1') ?? throw new RuntimeException('Invalid subscription id'),
                SubscriptionState::Active,
                new FilterCollection(),
            )),
        ]));
        $this->handler = $this->createHandlerWithRelayState($relayState);

        $response = $this->authenticatedRpc('{"method":"listsubscriptions"}');
        $data = $this->decodeResponse($response);

        $this->assertCount(1, self::arr($data['result']));
        $this->assertSame('sub1', self::arr(self::arr($data['result'])[0])['subscription_id']);
    }

    public function testGetConnectionReturnsClientData(): void
    {
        $relayState = $this->createStub(RelayStateInterface::class);
        $relayState->method('getConnectedClient')->willReturnCallback(
            static fn (ClientId $id) => 'abc123' === (string) $id ? new ClientSnapshot(
                self::client(),
                new SessionCounters(5, 4, 12),
                new SubscriptionSnapshotCollection(),
            ) : null
        );
        $this->handler = $this->createHandlerWithRelayState($relayState);

        $response = $this->authenticatedRpc('{"method":"getconnection","params":["abc123"]}');
        $data = $this->decodeResponse($response);

        $result = self::arr($data['result']);
        $this->assertSame('abc123', $result['id']);
        $this->assertSame('192.168.1.1', $result['ip']);
        $this->assertSame(5, $result['events_received']);
        $this->assertSame(4, $result['events_accepted']);
        $this->assertSame(12, $result['events_sent']);
    }

    public function testGetConnectionReturnsNullForUnknownId(): void
    {
        $relayState = $this->createStub(RelayStateInterface::class);
        $relayState->method('getConnectedClient')->willReturn(null);
        $this->handler = $this->createHandlerWithRelayState($relayState);

        $response = $this->authenticatedRpc('{"method":"getconnection","params":["missing"]}');
        $data = $this->decodeResponse($response);

        $this->assertNull($data['result']);
    }

    public function testGetConnectionWithoutIdReturnsError(): void
    {
        $relayState = $this->createStub(RelayStateInterface::class);
        $this->handler = $this->createHandlerWithRelayState($relayState);

        $response = $this->authenticatedRpc('{"method":"getconnection"}');
        $data = $this->decodeResponse($response);

        $this->assertArrayHasKey('error', $data);
        $this->assertStringContainsString('Missing connection id', $data['error']);
    }

    public function testGetWotScoreReturnsScoreForAuthenticatedUser(): void
    {
        $target = KeyPair::generate($this->signatureService());
        $followEvent = new Rumour(
            $this->tenantKeyPair->getPublicKey(),
            Timestamp::now(),
            EventKind::fromInt(EventKind::FOLLOW_LIST),
            new TagCollection([Tag::tryFromArray(['p', $target->getPublicKey()->toHex()])]),
            EventContent::empty(),
        )->sign($this->tenantKeyPair, $this->signatureService());
        $this->eventWriteStore->store($followEvent);

        $body = json_encode(
            ['method' => 'getwotscore', 'params' => [$target->getPublicKey()->toHex()]],
            JSON_THROW_ON_ERROR
        );
        $response = $this->authenticatedRpc($body);
        $data = $this->decodeResponse($response);

        $this->assertSame([
            'pubkey' => $target->getPublicKey()->toHex(),
            'followed' => true,
            'mutual_follows' => 0,
            'distance' => 1,
        ], $data['result']);
    }

    public function testGetWotScoreRejectsInvalidPubkey(): void
    {
        $body = json_encode(['method' => 'getwotscore', 'params' => ['not-a-pubkey']], JSON_THROW_ON_ERROR);
        $response = $this->authenticatedRpc($body);
        $data = $this->decodeResponse($response);

        $this->assertArrayHasKey('error', $data);
        $this->assertStringContainsString('Invalid pubkey', $data['error']);
    }

    public function testEverySupportedMethodIsDispatchable(): void
    {
        $supported = $this->decodeResponse($this->authenticatedRpc('{"method":"supportedmethods"}'))['result'];
        $this->assertIsArray($supported);
        $this->assertNotEmpty($supported);

        foreach ($supported as $method) {
            $this->assertIsString($method);
            $body = json_encode(['method' => $method], JSON_THROW_ON_ERROR);
            $data = $this->decodeResponse($this->authenticatedRpc($body));

            if (isset($data['error'])) {
                $this->assertStringNotContainsString('unknown method', self::str($data['error']), "method {$method} is listed but not dispatchable");
            }
        }
    }

    public function testUnknownMethodReturnsError(): void
    {
        $response = $this->authenticatedRpc('{"method":"nonexistent"}');

        $data = $this->decodeResponse($response);
        $this->assertStringContainsString('unknown method', self::str($data['error']));
    }

    private function authenticatedRpc(string $body): Response
    {
        $request = $this->request(
            'POST',
            ['content-type' => 'application/nostr+json+rpc', 'authorization' => $this->buildAuthHeader($body)],
            $body,
        );

        $response = $this->handler->handleRequest($request);
        $this->assertNotNull($response);

        return $response;
    }

    /**
     * @param non-empty-string                $method
     * @param array<non-empty-string, string> $headers
     */
    private function request(string $method, array $headers, string $body): Request
    {
        return new Request($this->createStub(Client::class), $method, Http::new('/'), $headers, $body);
    }

    private function buildAuthHeader(string $body): string
    {
        return $this->buildAuthHeaderWithKey($this->tenantKeyPair, $body);
    }

    private function buildAuthHeaderWithKey(KeyPair $keyPair, string $body): string
    {
        $payloadHash = hash('sha256', $body);
        $nonce = bin2hex(random_bytes(8));

        $rumour = new Rumour(
            $keyPair->getPublicKey(),
            Timestamp::now(),
            EventKind::fromInt(EventKind::HTTP_AUTH),
            new TagCollection([
                Tag::tryFromArray(['u', 'https://relay.example.com']),
                Tag::tryFromArray(['method', 'POST']),
                Tag::tryFromArray(['payload', $payloadHash]),
                Tag::tryFromArray(['nonce', $nonce]),
            ]),
            EventContent::empty()
        );

        $signed = $rumour->sign($keyPair, $this->signatureService());
        $json = json_encode($signed->toArray(), JSON_THROW_ON_ERROR);

        return 'Nostr '.base64_encode($json);
    }

    private function createRpcRequest(string $body): Request
    {
        return $this->request('POST', ['content-type' => 'application/nostr+json+rpc'], $body);
    }

    private function createHandlerWithRelayState(RelayStateInterface $relayState): RpcHandler
    {
        $relayUrl = RelayUrl::tryFromString('wss://relay.example.com') ?? throw new RuntimeException('Invalid URL');
        $nip98Validator = new Nip98Validator($this->signatureService(), new InMemoryNip98ReplayGuard(new SystemClock()), new SystemClock());
        $exploreQuery = new SqliteExploreQuery($this->pdo);
        $webOfTrustQuery = new SqliteWebOfTrustQuery($this->pdo);

        $writeCoordinator = new WriteCoordinator(new DirectWriteChannel($this->pdo));

        return new RpcHandler(
            new TenantAuthenticator($nip98Validator, $relayUrl, $this->policyState),
            [
                new TenancyRpcHandler($this->policyState, $this->policyManagement, new RemoveTenantUseCase($this->policyState, $this->policyManagement)),
                new BlockingRpcHandler($this->policyState, $this->policyManagement, new BanUseCase($this->policyState, $this->policyManagement, $writeCoordinator)),
                new RelayConfigRpcHandler($this->policyState, new ChangeRelayMetadataUseCase($this->policyState, $this->policyManagement), new UpdateRateLimitsUseCase($this->policyState, $this->policyManagement)),
                new RelayStateRpcHandler($relayState),
                new InsightsRpcHandler(new SqliteStatsProvider($this->pdo), $exploreQuery, $webOfTrustQuery),
            ],
        );
    }

    private static function client(): RelayClient
    {
        return new RelayClient(
            ClientId::fromString('abc123'),
            new ConnectionInfo(IpAddress::fromString('192.168.1.1'), 'test-client', Timestamp::fromInt(1700000000)),
        );
    }

    /**
     * @return array<array-key, mixed>
     */
    private function decodeResponse(Response $response): array
    {
        return (array) json_decode(buffer($response->getBody()), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function arr(mixed $value): array
    {
        self::assertIsArray($value);

        return $value;
    }

    private static function str(mixed $value): string
    {
        self::assertIsString($value);

        return $value;
    }
}
