<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Tests\Support;

use Amp\Http\Server\SocketHttpServer;
use Innis\Hubstr\Core\Domain\ValueObject\ConfigValues;
use Innis\Hubstr\Core\Infrastructure\Persistence\SchemaMigrator;
use Innis\Hubstr\Core\Infrastructure\Persistence\SqliteDatabase;
use Innis\Hubstr\Relay\Application\Service\HubstrPolicy;
use Innis\Hubstr\Relay\Domain\ValueObject\BlockedIp;
use Innis\Hubstr\Relay\Domain\ValueObject\GuestPolicy;
use Innis\Hubstr\Relay\Infrastructure\Config\RelayConfig;
use Innis\Hubstr\Relay\Infrastructure\Persistence\PolicyReadStore;
use Innis\Hubstr\Relay\Infrastructure\Persistence\PolicyState;
use Innis\Hubstr\Relay\Infrastructure\RateLimiting\PolicyStateRateLimitPolicy;
use Innis\Hubstr\Relay\Infrastructure\Relay\LiveRelayState;
use Innis\Hubstr\Relay\Tests\Fake\RecordingClientConnection;
use Innis\Nostr\Core\Domain\Collection\FilterCollection;
use Innis\Nostr\Core\Domain\Collection\TagCollection;
use Innis\Nostr\Core\Domain\Factory\RumourFactory;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventContent;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventKind;
use Innis\Nostr\Core\Domain\ValueObject\Identity\KeyPair;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Challenge;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Filter;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Client\AuthMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Client\CloseMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Client\CountMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Client\EventMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Client\ReqMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Rumour;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\SubscriptionId;
use Innis\Nostr\Core\Domain\ValueObject\Timestamp;
use Innis\Nostr\Core\Infrastructure\Crypto\NativeRandomBytesGenerator;
use Innis\Nostr\Relay\Application\Service\InMemoryAuthenticationRegistry;
use Innis\Nostr\Relay\Domain\Entity\RelayClient;
use Innis\Nostr\Relay\Domain\Exception\ConnectionException;
use Innis\Nostr\Relay\Domain\ValueObject\ClientId;
use Innis\Nostr\Relay\Domain\ValueObject\ConnectionInfo;
use Innis\Nostr\Relay\Domain\ValueObject\IpAddress;
use Innis\Nostr\Relay\Domain\ValueObject\RateLimitConfig;
use Innis\Nostr\Relay\Infrastructure\EventStore\InMemoryEventStore;
use Innis\Nostr\Relay\Infrastructure\Http\StaticNip11InfoProvider;
use Innis\Nostr\Relay\Infrastructure\Server\RelayServerFactory;
use Psr\Log\NullLogger;
use Throwable;

use function Amp\delay;

final class HostileSessionChurn
{
    public const int MAX_CONNECTIONS = 32;

    private const int ITERATIONS = 5000;
    private const int SEED = 20260721;
    private const int SIGNED_NOTES = 8;
    private const int AUTH_ANSWERS = 4;
    private const int CHURN_KEYS = 8;
    private const string INTERNAL_ERROR_NOTICE = 'Internal server error';

    private static ?HostileSessionChurnOutcome $outcome = null;

    // Deliberate: this fixture drives PolicyState's mutators directly, without the write-through coordinator, because its in-memory database has no durable state to diverge from — see ADR-0006
    public static function outcome(): HostileSessionChurnOutcome
    {
        return self::$outcome ??= self::run();
    }

    private static function run(): HostileSessionChurnOutcome
    {
        mt_srand(self::SEED);

        $signer = SignedEventFactory::signer();
        $tenant = KeyPair::generate($signer);

        $pdo = SqliteDatabase::inMemory()->connect();
        new SchemaMigrator($pdo)->migrate(dirname(__DIR__, 2).'/resources/migrations');

        $policyState = new PolicyState(new PolicyReadStore($pdo));
        $policyState->addTenant($tenant->getPublicKey());

        $authenticationRegistry = new InMemoryAuthenticationRegistry(new NativeRandomBytesGenerator());

        $config = RelayConfig::fromValues(ConfigValues::fromArray([
            'admin_pubkey' => $tenant->getPublicKey()->toHex(),
            'relay_url' => 'wss://churn.hubstr.test',
            'database_path' => ':memory:',
            'name' => 'Churn Hubstr Relay',
            'connection_limits' => ['max_connections' => self::MAX_CONNECTIONS],
        ]));

        $relay = new RelayServerFactory(
            eventStore: new InMemoryEventStore(),
            policy: new HubstrPolicy($policyState, $authenticationRegistry, $config->getRelayLimits()),
            config: $config,
            rateLimitPolicy: new PolicyStateRateLimitPolicy($policyState),
            authenticationRegistry: $authenticationRegistry,
            logger: new NullLogger(),
            nip11InfoProvider: new StaticNip11InfoProvider($config->getRelayInfo()),
            signatureService: $signer,
            connectionGate: $policyState,
        )->create(SocketHttpServer::createForDirectAccess(new NullLogger()));

        $coordinator = $relay->getSessionCoordinator();
        $relayState = new LiveRelayState($relay);

        $signedNotes = [];
        for ($i = 0; $i < self::SIGNED_NOTES; ++$i) {
            $note = new Rumour($tenant->getPublicKey(), Timestamp::now(), EventKind::fromInt(EventKind::TEXT_NOTE), new TagCollection(), EventContent::fromString('tenant churn note '.$i))->sign($tenant, $signer);
            $signedNotes[] = new EventMessage($note)->toJson();
        }

        $authAnswers = [];
        for ($i = 0; $i < self::AUTH_ANSWERS; ++$i) {
            $authEvent = RumourFactory::createAuth($tenant->getPublicKey(), $config->getRelayUrl(), Challenge::fromString('challenge-'.$i))->sign($tenant, $signer);
            $authAnswers[] = new AuthMessage($authEvent)->toJson();
        }

        $churnKeys = [];
        for ($i = 0; $i < self::CHURN_KEYS; ++$i) {
            $churnKeys[] = KeyPair::generate($signer)->getPublicKey();
        }

        /** @var array<string, RelayClient> $clientsById */
        $clientsById = [];
        /** @var list<RecordingClientConnection> $connections */
        $connections = [];
        /** @var list<string> $escapedFaults */
        $escapedFaults = [];
        $mostClientsRegistered = 0;
        $subscriptionId = SubscriptionId::generate();

        for ($step = 0; $step < self::ITERATIONS; ++$step) {
            $roll = mt_rand(0, 99);
            $operation = ([] === $clientsById || $roll < 25) ? 'open' : ($roll < 80 ? 'route' : 'close');

            try {
                match ($operation) {
                    'open' => self::open($coordinator->open(...), $clientsById, $connections),
                    'route' => self::route($coordinator->route(...), $clientsById, $subscriptionId, $signedNotes, $authAnswers),
                    'close' => self::close($coordinator->close(...), $clientsById),
                };
            } catch (ConnectionException) {
            } catch (Throwable $fault) {
                $escapedFaults[] = sprintf('iteration %d, %s: %s: %s', $step, $operation, $fault::class, $fault->getMessage());
            } finally {
                delay(0);
            }

            if (0 === mt_rand(0, 3)) {
                self::mutatePolicy($policyState, $churnKeys);
            }

            if (0 === mt_rand(0, 9)) {
                try {
                    $relayState->getConnectedClients();
                    $relayState->getActiveSubscriptions();
                    $policyState->isIpAllowed(self::randomIp());
                } catch (Throwable $fault) {
                    $escapedFaults[] = sprintf('iteration %d, snapshot: %s: %s', $step, $fault::class, $fault->getMessage());
                }
            }

            $mostClientsRegistered = max($mostClientsRegistered, $relay->getClients()->count());
        }

        foreach ($clientsById as $client) {
            try {
                $coordinator->close($client->getId());
            } catch (Throwable) {
            }
        }

        for ($settle = 0; $settle < 50; ++$settle) {
            delay(0);
        }

        return new HostileSessionChurnOutcome(
            $escapedFaults,
            self::countInternalErrorNotices($connections),
            $mostClientsRegistered,
            $relay->getClients()->count(),
            $relay->getSubscriptions()->count(),
            $relay->getMetrics(),
        );
    }

    /**
     * @param callable(RecordingClientConnection, ConnectionInfo): RelayClient $open
     * @param array<string, RelayClient>                                       $clientsById
     * @param list<RecordingClientConnection>                                  $connections
     */
    private static function open(callable $open, array &$clientsById, array &$connections): void
    {
        $connection = new RecordingClientConnection(failAfterSends: mt_rand(0, 30));
        $connections[] = $connection;
        $client = $open($connection, new ConnectionInfo(self::randomIp(), 'churn-client', Timestamp::now()));
        $clientsById[(string) $client->getId()] = $client;
    }

    /**
     * @param callable(RelayClient, string): mixed $route
     * @param array<string, RelayClient>           $clientsById
     * @param list<string>                         $signedNotes
     * @param list<string>                         $authAnswers
     */
    private static function route(callable $route, array $clientsById, SubscriptionId &$subscriptionId, array $signedNotes, array $authAnswers): void
    {
        $client = self::pick($clientsById);

        if (null === $client) {
            return;
        }

        if (0 === mt_rand(0, 3)) {
            $subscriptionId = SubscriptionId::generate();
        }

        $route($client, self::frame($subscriptionId, $signedNotes, $authAnswers));
    }

    /**
     * @param callable(ClientId): mixed  $close
     * @param array<string, RelayClient> $clientsById
     */
    private static function close(callable $close, array &$clientsById): void
    {
        $client = self::pick($clientsById);

        if (null === $client) {
            return;
        }

        $close($client->getId());
        unset($clientsById[(string) $client->getId()]);
    }

    /**
     * @param array<string, RelayClient> $clientsById
     */
    private static function pick(array $clientsById): ?RelayClient
    {
        $ids = array_keys($clientsById);

        return [] === $ids ? null : $clientsById[$ids[mt_rand(0, count($ids) - 1)]];
    }

    /**
     * @param list<string> $signedNotes
     * @param list<string> $authAnswers
     */
    private static function frame(SubscriptionId $subscriptionId, array $signedNotes, array $authAnswers): string
    {
        $everything = new FilterCollection([new Filter()]);
        $id = (string) $subscriptionId;

        $menu = [
            $signedNotes[mt_rand(0, count($signedNotes) - 1)],
            $authAnswers[mt_rand(0, count($authAnswers) - 1)],
            new ReqMessage($subscriptionId, $everything)->toJson(),
            new CloseMessage($subscriptionId)->toJson(),
            new CountMessage($subscriptionId, $everything)->toJson(),
            '{',
            '',
            'not json at all',
            '["unterminated',
            '[]',
            '["EVENT"]',
            '["REQ"]',
            '["REQ","'.$id.'"]',
            '["EVENT",123,{}]',
            '["EVENT",{"an":"object"}]',
            '["CLOSE"]',
            '["OK","not-a-client-message",true,""]',
            '12345',
            'true',
            'null',
            '{"an":"object"}',
            '["WAT_UNKNOWN",1,2,3]',
            '["REQ","'.$id.'",{"kinds":"not-an-array"}]',
            '["EVENT","'.$id.'",{"kind":"not-an-int"}]',
            sprintf('["REQ","%s",%s]', $id, '{"authors":['.str_repeat('"x",', 20000).'"x"]}'),
        ];

        return $menu[mt_rand(0, count($menu) - 1)];
    }

    /**
     * @param list<PublicKey> $churnKeys
     */
    private static function mutatePolicy(PolicyState $policyState, array $churnKeys): void
    {
        $key = $churnKeys[mt_rand(0, count($churnKeys) - 1)];

        match (mt_rand(0, 6)) {
            0 => $policyState->addTenant($key),
            1 => $policyState->removeTenant($key),
            2 => $policyState->setGuestPolicy(GuestPolicy::defaults()),
            3 => $policyState->banPubkey($key),
            4 => $policyState->unbanPubkey($key),
            5 => $policyState->setRateLimits(new RateLimitConfig(eventsPerMinute: mt_rand(1, 100000), subscriptionsPerMinute: mt_rand(1, 100000))),
            default => $policyState->blockIp(BlockedIp::fromParts(self::randomIp(), 'churn')),
        };
    }

    private static function randomIp(): IpAddress
    {
        return IpAddress::fromString(sprintf('10.%d.%d.%d', mt_rand(0, 255), mt_rand(0, 255), mt_rand(1, 254)));
    }

    /**
     * @param list<RecordingClientConnection> $connections
     */
    private static function countInternalErrorNotices(array $connections): int
    {
        $notices = 0;

        foreach ($connections as $connection) {
            foreach ($connection->sentFrames() as $frame) {
                if (str_contains($frame, self::INTERNAL_ERROR_NOTICE)) {
                    ++$notices;
                }
            }
        }

        return $notices;
    }
}
