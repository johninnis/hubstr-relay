<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Tests\Integration\Application\Service;

use Innis\Hubstr\Core\Domain\ValueObject\ConfigValues;
use Innis\Hubstr\Core\Infrastructure\Persistence\SchemaMigrator;
use Innis\Hubstr\Core\Infrastructure\Persistence\SqliteDatabase;
use Innis\Hubstr\Relay\Application\Service\Nip11InfoProvider;
use Innis\Hubstr\Relay\Domain\ValueObject\GuestPolicy;
use Innis\Hubstr\Relay\Domain\ValueObject\RelayLimits;
use Innis\Hubstr\Relay\Domain\ValueObject\RelayMetadata;
use Innis\Hubstr\Relay\Infrastructure\Config\RelayConfig;
use Innis\Hubstr\Relay\Infrastructure\Persistence\PolicyReadStore;
use Innis\Hubstr\Relay\Infrastructure\Persistence\PolicyState;
use Innis\Hubstr\Relay\Infrastructure\Persistence\WriteThroughPolicyManagement;
use Innis\Hubstr\Relay\Infrastructure\Worker\WriteCoordinator;
use Innis\Hubstr\Relay\Tests\Fake\DirectWriteChannel;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventKind;
use PDO;
use PHPUnit\Framework\TestCase;

final class Nip11InfoProviderTest extends TestCase
{
    public function testReturnsConfigValuesWhenNoOverrides(): void
    {
        $policyState = self::createPolicyState();

        $provider = new Nip11InfoProvider(self::createConfig()->getRelayInfo(), $policyState, RelayLimits::defaults());
        $info = $provider->getNip11Info();

        $this->assertSame('Test Relay', $info->getName());
        $this->assertSame('A test relay', $info->getDescription());
    }

    public function testOverridesFromPolicyStore(): void
    {
        $pdo = self::createDatabase();
        $policyState = new PolicyState(new PolicyReadStore($pdo));
        $policyManagement = self::createPolicyManagement($pdo, $policyState);

        $policyManagement->setMetadata(RelayMetadata::fromStored('Custom Name', 'Custom Desc', 'https://example.com/icon.png'));

        $provider = new Nip11InfoProvider(self::createConfig()->getRelayInfo(), $policyState, RelayLimits::defaults());
        $info = $provider->getNip11Info();

        $this->assertSame('Custom Name', $info->getName());
        $this->assertSame('Custom Desc', $info->getDescription());
        $this->assertSame('https://example.com/icon.png', $info->getIcon());
    }

    public function testExposesEnforcedLimitationBlock(): void
    {
        $provider = new Nip11InfoProvider(self::createConfig()->getRelayInfo(), self::createPolicyState(), RelayLimits::defaults());

        $limitation = $provider->getNip11Info()->getLimitation();

        $this->assertNotNull($limitation);
        $this->assertSame(20, $limitation['max_subscriptions']);
        $this->assertSame(5, $limitation['max_filters']);
        $this->assertSame(1000, $limitation['max_limit']);
        $this->assertSame(65536, $limitation['max_content_length']);
        $this->assertFalse($limitation['auth_required']);
        $this->assertFalse($limitation['payment_required']);
        $this->assertTrue($limitation['restricted_writes']);
    }

    public function testExposesContactAndIconFromConfig(): void
    {
        $config = RelayConfig::fromValues(ConfigValues::fromArray([
            'admin_pubkey' => str_repeat('aa', 32),
            'relay_url' => 'wss://relay.example.com',
            'database_path' => '/tmp/test.sqlite',
            'contact' => 'mailto:admin@example.com',
            'icon' => 'https://relay.example.com/icon.svg',
        ]));

        $info = new Nip11InfoProvider($config->getRelayInfo(), self::createPolicyState(), RelayLimits::defaults())->getNip11Info();

        $this->assertSame('mailto:admin@example.com', $info->getContact());
        $this->assertSame('https://relay.example.com/icon.svg', $info->getIcon());
    }

    public function testAdvertisesTheDirectMessageInboxWhileTheDefaultPolicyProvidesOne(): void
    {
        $provider = new Nip11InfoProvider(self::createConfig()->getRelayInfo(), self::createPolicyState(), RelayLimits::defaults());

        $this->assertSame([1, 9, 11, 17, 40, 42, 45, 50, 70, 86, 98], $provider->getNip11Info()->getSupportedNips());
    }

    public function testStopsAdvertisingTheInboxOnceGiftWrapsBecomeGuestReadable(): void
    {
        $pdo = self::createDatabase();
        $policyState = new PolicyState(new PolicyReadStore($pdo));
        $provider = new Nip11InfoProvider(self::createConfig()->getRelayInfo(), $policyState, RelayLimits::defaults());

        self::createPolicyManagement($pdo, $policyState)->setGuestPolicy(GuestPolicy::fromArray([
            'read' => ['kinds' => [EventKind::GIFT_WRAP]],
        ]));

        $this->assertSame([1, 9, 11, 40, 42, 45, 50, 70, 86, 98], $provider->getNip11Info()->getSupportedNips());
    }

    private static function createConfig(): RelayConfig
    {
        return RelayConfig::fromValues(ConfigValues::fromArray([
            'admin_pubkey' => str_repeat('aa', 32),
            'relay_url' => 'wss://relay.example.com',
            'database_path' => '/tmp/test.sqlite',
            'name' => 'Test Relay',
            'description' => 'A test relay',
        ]));
    }

    private static function createDatabase(): PDO
    {
        $pdo = SqliteDatabase::inMemory()->connect();
        new SchemaMigrator($pdo)->migrate(dirname(__DIR__, 4).'/resources/migrations');

        return $pdo;
    }

    private static function createPolicyState(): PolicyState
    {
        return new PolicyState(new PolicyReadStore(self::createDatabase()));
    }

    private static function createPolicyManagement(PDO $pdo, PolicyState $policyState): WriteThroughPolicyManagement
    {
        return new WriteThroughPolicyManagement($policyState, new WriteCoordinator(new DirectWriteChannel($pdo)));
    }
}
