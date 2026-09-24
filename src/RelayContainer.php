<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay;

use Amp\Http\Server\SocketHttpServer;
use Amp\Parallel\Worker\ContextWorkerPool;
use Amp\Parallel\Worker\WorkerPool;
use Amp\Socket\ResourceServerSocketFactory;
use Amp\Sync\Channel;
use Innis\Hubstr\Core\Application\Port\LifecycleInterface;
use Innis\Hubstr\Core\Application\Port\ServerInterface;
use Innis\Hubstr\Core\Application\Port\TemplateRendererInterface;
use Innis\Hubstr\Core\Domain\Enum\HttpMethod;
use Innis\Hubstr\Core\Infrastructure\Http\HttpServerFactory;
use Innis\Hubstr\Core\Infrastructure\Http\HttpServerOptions;
use Innis\Hubstr\Core\Infrastructure\Http\Route;
use Innis\Hubstr\Core\Infrastructure\Http\RouterDefinition;
use Innis\Hubstr\Core\Infrastructure\Persistence\SchemaMigrator;
use Innis\Hubstr\Core\Infrastructure\Persistence\SqliteDatabase;
use Innis\Hubstr\Core\Infrastructure\Templating\LatteTemplateRenderer;
use Innis\Hubstr\Core\Presentation\Http\ErrorPageResponder;
use Innis\Hubstr\Core\Presentation\Http\LandingPageResponder;
use Innis\Hubstr\Core\Presentation\Http\TemplatedErrorHandler;
use Innis\Hubstr\Relay\Application\Port\RelayStateInterface;
use Innis\Hubstr\Relay\Application\Service\HubstrPolicy;
use Innis\Hubstr\Relay\Application\Service\Nip11InfoProvider;
use Innis\Hubstr\Relay\Application\Service\TenantAuthenticator;
use Innis\Hubstr\Relay\Application\UseCase\BanUseCase;
use Innis\Hubstr\Relay\Application\UseCase\ChangeRelayMetadataUseCase;
use Innis\Hubstr\Relay\Application\UseCase\ExportEventsUseCase;
use Innis\Hubstr\Relay\Application\UseCase\ImportEventUseCase;
use Innis\Hubstr\Relay\Application\UseCase\RemoveTenantUseCase;
use Innis\Hubstr\Relay\Application\UseCase\UpdateRateLimitsUseCase;
use Innis\Hubstr\Relay\Infrastructure\Auth\InMemoryNip98ReplayGuard;
use Innis\Hubstr\Relay\Infrastructure\Collection\LifecycleCollection;
use Innis\Hubstr\Relay\Infrastructure\Config\RelayConfig;
use Innis\Hubstr\Relay\Infrastructure\Persistence\CachingExploreQuery;
use Innis\Hubstr\Relay\Infrastructure\Persistence\CachingStatsProvider;
use Innis\Hubstr\Relay\Infrastructure\Persistence\PolicyReadStore;
use Innis\Hubstr\Relay\Infrastructure\Persistence\PolicyState;
use Innis\Hubstr\Relay\Infrastructure\Persistence\StatementRunner;
use Innis\Hubstr\Relay\Infrastructure\Persistence\StatPayloadCodec;
use Innis\Hubstr\Relay\Infrastructure\Persistence\StatResultCache;
use Innis\Hubstr\Relay\Infrastructure\Persistence\StatResultsStore;
use Innis\Hubstr\Relay\Infrastructure\Persistence\WorkerEventStore;
use Innis\Hubstr\Relay\Infrastructure\Persistence\WorkerExploreQuery;
use Innis\Hubstr\Relay\Infrastructure\Persistence\WorkerStatsProvider;
use Innis\Hubstr\Relay\Infrastructure\Persistence\WorkerWebOfTrustQuery;
use Innis\Hubstr\Relay\Infrastructure\Persistence\WriteThroughPolicyManagement;
use Innis\Hubstr\Relay\Infrastructure\Process\RelayLifecycle;
use Innis\Hubstr\Relay\Infrastructure\RateLimiting\PolicyStateRateLimitPolicy;
use Innis\Hubstr\Relay\Infrastructure\Relay\LiveRelayState;
use Innis\Hubstr\Relay\Infrastructure\Retention\ExpirySweepScheduler;
use Innis\Hubstr\Relay\Infrastructure\Stats\StatsRefreshSchedule;
use Innis\Hubstr\Relay\Infrastructure\Stats\StatsRefreshScheduler;
use Innis\Hubstr\Relay\Infrastructure\Worker\ReadContext;
use Innis\Hubstr\Relay\Infrastructure\Worker\ReadWorkerPool;
use Innis\Hubstr\Relay\Infrastructure\Worker\ReadWorkerTask;
use Innis\Hubstr\Relay\Infrastructure\Worker\WriteContext;
use Innis\Hubstr\Relay\Infrastructure\Worker\WriteCoordinator;
use Innis\Hubstr\Relay\Infrastructure\Worker\WriteWorkerTask;
use Innis\Hubstr\Relay\Presentation\Http\Middleware\CorsHeaders;
use Innis\Hubstr\Relay\Presentation\Http\Middleware\CorsMiddleware;
use Innis\Hubstr\Relay\Presentation\Http\Nip11SiteInfo;
use Innis\Hubstr\Relay\Presentation\Http\RootRequestHandler;
use Innis\Hubstr\Relay\Presentation\Http\Rpc\BlockingRpcHandler;
use Innis\Hubstr\Relay\Presentation\Http\Rpc\InsightsRpcHandler;
use Innis\Hubstr\Relay\Presentation\Http\Rpc\RelayConfigRpcHandler;
use Innis\Hubstr\Relay\Presentation\Http\Rpc\RelayStateRpcHandler;
use Innis\Hubstr\Relay\Presentation\Http\Rpc\TenancyRpcHandler;
use Innis\Hubstr\Relay\Presentation\Http\RpcHandler;
use Innis\Nostr\Core\Application\Port\ClockInterface;
use Innis\Nostr\Core\Application\Port\RandomBytesGeneratorInterface;
use Innis\Nostr\Core\Application\Service\Nip98Validator;
use Innis\Nostr\Core\Domain\Service\EventValidator;
use Innis\Nostr\Core\Domain\Service\NipComplianceValidator;
use Innis\Nostr\Core\Domain\Service\SignatureServiceInterface;
use Innis\Nostr\Core\Infrastructure\Crypto\NativeRandomBytesGenerator;
use Innis\Nostr\Core\Infrastructure\Crypto\Secp256k1Signer;
use Innis\Nostr\Core\Infrastructure\Time\SystemClock;
use Innis\Nostr\Relay\Application\Service\AuthenticationRegistryInterface;
use Innis\Nostr\Relay\Application\Service\InMemoryAuthenticationRegistry;
use Innis\Nostr\Relay\Infrastructure\Server\RelayInstance;
use Innis\Nostr\Relay\Infrastructure\Server\RelayServerFactory;
use PDO;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class RelayContainer
{
    private const int CONCURRENCY_LIMIT = 1000;

    private const int READ_WORKER_COUNT = 4;

    private ?PDO $pdo = null;
    private ?SqliteDatabase $sqliteDatabase = null;
    private ?WorkerPool $writeWorkerPool = null;
    private ?WorkerPool $readWorkerPool = null;
    private ?WriteCoordinator $writeCoordinator = null;
    private ?ReadWorkerPool $readWorkers = null;
    private ?PolicyState $policyState = null;
    private ?WriteThroughPolicyManagement $policyManagement = null;
    private ?AuthenticationRegistryInterface $authenticationRegistry = null;
    private ?ClockInterface $clock = null;
    private ?RandomBytesGeneratorInterface $randomBytes = null;
    private ?SignatureServiceInterface $signer = null;
    private ?StatResultCache $statResultCache = null;
    private ?StatPayloadCodec $statPayloadCodec = null;
    private ?Nip11InfoProvider $nip11InfoProvider = null;
    private ?TemplateRendererInterface $templateRenderer = null;
    private ?Nip11SiteInfo $siteInfoProvider = null;
    private ?StatsRefreshScheduler $scheduler = null;
    private ?ExpirySweepScheduler $expirySweepScheduler = null;

    public function __construct(
        private readonly RelayConfig $config,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    private function database(): PDO
    {
        return $this->pdo ??= $this->sqliteDatabase()->connect();
    }

    private function sqliteDatabase(): SqliteDatabase
    {
        return $this->sqliteDatabase ??= SqliteDatabase::atPath($this->config->getRuntime()->getDatabasePath());
    }

    private function migrate(): void
    {
        new SchemaMigrator($this->database())->migrate(dirname(__DIR__).'/resources/migrations');
    }

    public function bootstrap(): void
    {
        $this->migrate();

        $this->loadedPolicyState();
        $this->policyManagement()->addTenant($this->config->getAdminPubkey());
    }

    // Deliberate: the CLI tools write in-process through the concrete stores, never the write worker — see ADR-0008
    public function importEventUseCase(): ImportEventUseCase
    {
        $this->migrate();

        return new ImportEventUseCase(
            new EventValidator($this->signer(), new NipComplianceValidator($this->signer())),
            $this->loadedPolicyState(),
            WriteContext::forConnection($this->database())->getEventWriteStore(),
        );
    }

    // Deliberate: reads on its own connection rather than through the read pool, because a CLI tool is not the running relay — see ADR-0008
    public function exportEventsUseCase(): ExportEventsUseCase
    {
        $this->migrate();

        return new ExportEventsUseCase(ReadContext::forConnection($this->database())->getEventQueryStore());
    }

    public function server(): ServerInterface
    {
        $factory = new HttpServerFactory($this->logger, new ResourceServerSocketFactory());
        $socketServer = $factory->createSocketServer($this->config->getRuntime()->getBinding(), $this->httpServerOptions());
        $relay = $this->createRelay($socketServer);

        $root = new RootRequestHandler(
            $relay->getRequestHandler(),
            $this->rpcHandler(new LiveRelayState($relay)),
            new LandingPageResponder('index.latte', $this->templateRenderer(), $this->siteInfoProvider()),
        );

        return $factory->createServer($socketServer, new RouterDefinition(
            [
                new Route(HttpMethod::Get, '/', $root->handleRequest(...)),
                new Route(HttpMethod::Post, '/', $root->handleRequest(...)),
            ],
            $this->errorHandler(),
            $this->publicDirectory(),
        ));
    }

    private function httpServerOptions(): HttpServerOptions
    {
        return HttpServerOptions::create(self::CONCURRENCY_LIMIT, middleware: [new CorsMiddleware(new CorsHeaders())]);
    }

    private function writeWorkerPool(): WorkerPool
    {
        return $this->writeWorkerPool ??= new ContextWorkerPool(limit: 1);
    }

    private function readWorkerPool(): WorkerPool
    {
        return $this->readWorkerPool ??= new ContextWorkerPool(limit: self::READ_WORKER_COUNT);
    }

    private function writeCoordinator(): WriteCoordinator
    {
        return $this->writeCoordinator ??= new WriteCoordinator(
            $this->writeWorkerPool()->submit(new WriteWorkerTask($this->sqliteDatabase()))->getChannel(),
            $this->logger,
        );
    }

    private function readWorkers(): ReadWorkerPool
    {
        return $this->readWorkers ??= $this->createReadWorkerPool();
    }

    private function createReadWorkerPool(): ReadWorkerPool
    {
        $database = $this->sqliteDatabase();
        $readPool = $this->readWorkerPool();
        $readChannelFactory = static fn (): Channel => $readPool->submit(new ReadWorkerTask($database))->getChannel();

        $readChannels = array_map(
            static fn (): Channel => $readChannelFactory(),
            range(1, self::READ_WORKER_COUNT),
        );

        return new ReadWorkerPool($readChannels, $readChannelFactory);
    }

    private function policyState(): PolicyState
    {
        return $this->policyState ??= new PolicyState(new PolicyReadStore($this->database()));
    }

    private function loadedPolicyState(): PolicyState
    {
        $policyState = $this->policyState();
        $policyState->loadFromDatabase();

        return $policyState;
    }

    private function policyManagement(): WriteThroughPolicyManagement
    {
        return $this->policyManagement ??= new WriteThroughPolicyManagement($this->policyState(), $this->writeCoordinator());
    }

    private function authenticationRegistry(): AuthenticationRegistryInterface
    {
        return $this->authenticationRegistry ??= new InMemoryAuthenticationRegistry($this->randomBytes());
    }

    private function randomBytes(): RandomBytesGeneratorInterface
    {
        return $this->randomBytes ??= new NativeRandomBytesGenerator();
    }

    private function clock(): ClockInterface
    {
        return $this->clock ??= new SystemClock();
    }

    private function signer(): SignatureServiceInterface
    {
        return $this->signer ??= Secp256k1Signer::create();
    }

    private function statResultCache(): StatResultCache
    {
        // Main-process connection on purpose: the hot cache hit is a single-row PK lookup. See ADR-0009.
        return $this->statResultCache ??= new StatResultCache(new StatResultsStore(new StatementRunner($this->database())), $this->writeCoordinator());
    }

    private function statPayloadCodec(): StatPayloadCodec
    {
        return $this->statPayloadCodec ??= new StatPayloadCodec();
    }

    private function nip11InfoProvider(): Nip11InfoProvider
    {
        return $this->nip11InfoProvider ??= new Nip11InfoProvider($this->config->getRelayInfo(), $this->policyState(), $this->config->getRelayLimits());
    }

    private function createRelay(SocketHttpServer $httpServer): RelayInstance
    {
        return new RelayServerFactory(
            eventStore: new WorkerEventStore($this->writeCoordinator(), $this->readWorkers(), $this->config->getRelayLimits()->getMaxLimit()),
            policy: new HubstrPolicy($this->policyState(), $this->authenticationRegistry(), $this->config->getRelayLimits()),
            config: $this->config,
            rateLimitPolicy: new PolicyStateRateLimitPolicy($this->policyState()),
            authenticationRegistry: $this->authenticationRegistry(),
            logger: $this->logger,
            nip11InfoProvider: $this->nip11InfoProvider(),
            signatureService: $this->signer(),
            connectionGate: $this->policyState(),
            randomBytes: $this->randomBytes(),
        )->create($httpServer);
    }

    private function rpcHandler(RelayStateInterface $relayState): RpcHandler
    {
        $tenancyHandler = new TenancyRpcHandler(
            $this->policyState(),
            $this->policyManagement(),
            new RemoveTenantUseCase($this->policyState(), $this->policyManagement()),
        );
        $blockingHandler = new BlockingRpcHandler(
            $this->policyState(),
            $this->policyManagement(),
            new BanUseCase($this->policyState(), $this->policyManagement(), $this->writeCoordinator()),
        );
        $relayConfigHandler = new RelayConfigRpcHandler(
            $this->policyState(),
            new ChangeRelayMetadataUseCase($this->policyState(), $this->policyManagement()),
            new UpdateRateLimitsUseCase($this->policyState(), $this->policyManagement()),
        );
        $relayStateHandler = new RelayStateRpcHandler($relayState);
        $insightsHandler = new InsightsRpcHandler(
            new CachingStatsProvider(new WorkerStatsProvider($this->readWorkers()), $this->statResultCache(), $this->statPayloadCodec()),
            new CachingExploreQuery(new WorkerExploreQuery($this->readWorkers()), $this->statResultCache(), $this->statPayloadCodec()),
            new WorkerWebOfTrustQuery($this->readWorkers()),
        );

        return new RpcHandler(
            $this->tenantAuthenticator(),
            [$tenancyHandler, $blockingHandler, $relayConfigHandler, $relayStateHandler, $insightsHandler],
            $this->logger,
        );
    }

    private function tenantAuthenticator(): TenantAuthenticator
    {
        return new TenantAuthenticator(
            new Nip98Validator($this->signer(), replayGuard: new InMemoryNip98ReplayGuard($this->clock()), clock: $this->clock()),
            $this->config->getRelayUrl(),
            $this->policyState(),
        );
    }

    private function errorHandler(): TemplatedErrorHandler
    {
        return new TemplatedErrorHandler(new ErrorPageResponder('error.latte', $this->templateRenderer(), $this->siteInfoProvider()));
    }

    private function siteInfoProvider(): Nip11SiteInfo
    {
        return $this->siteInfoProvider ??= new Nip11SiteInfo($this->nip11InfoProvider());
    }

    private function templateRenderer(): TemplateRendererInterface
    {
        return $this->templateRenderer ??= LatteTemplateRenderer::create(
            dirname(__DIR__).'/templates',
            dirname(__DIR__).'/var/cache/latte',
        );
    }

    private function publicDirectory(): string
    {
        return dirname(__DIR__).'/public';
    }

    public function lifecycle(): LifecycleInterface
    {
        return new RelayLifecycle(
            new LifecycleCollection([$this->scheduler(), $this->expirySweepScheduler()]),
            $this->writeWorkerPool(),
            $this->readWorkerPool(),
        );
    }

    private function expirySweepScheduler(): ExpirySweepScheduler
    {
        return $this->expirySweepScheduler ??= new ExpirySweepScheduler($this->writeCoordinator(), $this->logger);
    }

    private function scheduler(): StatsRefreshScheduler
    {
        return $this->scheduler ??= new StatsRefreshScheduler(
            new ContextWorkerPool(limit: 1),
            StatsRefreshSchedule::forDatabase($this->sqliteDatabase()),
            $this->writeCoordinator(),
            $this->logger,
        );
    }
}
