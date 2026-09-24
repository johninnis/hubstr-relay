<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Tests\Unit\Infrastructure\Config;

use Innis\Hubstr\Core\Domain\Enum\LogLevel;
use Innis\Hubstr\Core\Domain\ValueObject\ConfigValues;
use Innis\Hubstr\Relay\Infrastructure\Config\RelayConfig;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class RelayConfigTest extends TestCase
{
    public function testCreatesFromValidConfig(): void
    {
        $config = RelayConfig::fromValues(ConfigValues::fromArray($this->validConfig()));

        $this->assertSame('127.0.0.1', $config->getRuntime()->getBinding()->getHost());
        $this->assertSame(8080, $config->getRuntime()->getBinding()->getPort());
        $this->assertSame(100, $config->getMaxConnections());
        $this->assertSame(str_repeat('aa', 32), $config->getAdminPubkey()->toHex());
        $this->assertSame('/tmp/test.sqlite', $config->getRuntime()->getDatabasePath());
        $this->assertSame(LogLevel::Info, $config->getRuntime()->getLogLevel());
    }

    public function testRelayInfoFromConfig(): void
    {
        $config = RelayConfig::fromValues(ConfigValues::fromArray($this->validConfig()));
        $info = $config->getRelayInfo();

        $this->assertSame('Test Relay', $info->getName());
        $this->assertSame('A test relay', $info->getDescription());
        $this->assertSame([1, 9, 11, 40, 42, 45, 50, 70, 86, 98], $info->getSupportedNips());
        $this->assertSame('hubstr-relay', $info->getSoftware());
        $this->assertSame('wss://relay.example.com', (string) $config->getRelayUrl());
        $this->assertSame((string) $config->getRelayUrl(), (string) $info->getRelayUrl());
    }

    public function testMaxConnectionsFallsBackToDefaultWhenAbsent(): void
    {
        $data = $this->validConfig();
        unset($data['connection_limits']);

        $this->assertSame(100, RelayConfig::fromValues(ConfigValues::fromArray($data))->getMaxConnections());
    }

    public function testRelayLimitsFallBackToDefaultsWhenAbsent(): void
    {
        $limits = RelayConfig::fromValues(ConfigValues::fromArray($this->validConfig()))->getRelayLimits();

        $this->assertSame(20, $limits->getMaxSubscriptions());
        $this->assertSame(5, $limits->getMaxFilters());
        $this->assertSame(1000, $limits->getMaxLimit());
        $this->assertSame(65536, $limits->getMaxContentLength());
    }

    public function testRelayLimitsUseConfigValuesWithPerFieldDefaults(): void
    {
        $data = $this->validConfig();
        $data['limits'] = ['max_subscriptions' => 50, 'max_content_length' => 1024];

        $limits = RelayConfig::fromValues(ConfigValues::fromArray($data))->getRelayLimits();

        $this->assertSame(50, $limits->getMaxSubscriptions());
        $this->assertSame(1024, $limits->getMaxContentLength());
        $this->assertSame(5, $limits->getMaxFilters());
        $this->assertSame(1000, $limits->getMaxLimit());
    }

    public function testRejectsAMaxConnectionsThatIsNotAnInteger(): void
    {
        $data = $this->validConfig();
        $data['connection_limits'] = ['max_connections' => '250'];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('connection_limits.max_connections must be an integer, got string');

        RelayConfig::fromValues(ConfigValues::fromArray($data));
    }

    public function testRejectsARelayLimitThatIsNotAnInteger(): void
    {
        $data = $this->validConfig();
        $data['limits'] = ['max_filters' => 'five'];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('limits.max_filters must be an integer, got string');

        RelayConfig::fromValues(ConfigValues::fromArray($data));
    }

    public function testRejectsAMaxLimitOutsideTheRangeAFilterCanCarry(): void
    {
        $data = $this->validConfig();
        $data['limits'] = ['max_limit' => 0];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('limits.max_limit must be between 1 and');

        RelayConfig::fromValues(ConfigValues::fromArray($data));
    }

    public function testRejectsAKeyItDoesNotKnow(): void
    {
        $data = $this->validConfig();
        $data['relay_ulr'] = 'wss://typo.example.com';

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown config key: relay_ulr');

        RelayConfig::fromValues(ConfigValues::fromArray($data));
    }

    public function testRejectsAKeyItDoesNotKnowInsideASection(): void
    {
        $data = $this->validConfig();
        $data['limits'] = ['max_filtres' => 5];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown config key: limits.max_filtres');

        RelayConfig::fromValues(ConfigValues::fromArray($data));
    }

    public function testAcceptsTheAdminPubkeyAsAnNpub(): void
    {
        $data = $this->validConfig();
        $data['admin_pubkey'] = PublicKey::tryFromHex(str_repeat('aa', 32))?->toBech32();

        $config = RelayConfig::fromValues(ConfigValues::fromArray($data));

        $this->assertSame(str_repeat('aa', 32), $config->getAdminPubkey()->toHex());
    }

    public function testThrowsOnMissingAdminPubkey(): void
    {
        $data = $this->validConfig();
        unset($data['admin_pubkey']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('admin_pubkey');

        RelayConfig::fromValues(ConfigValues::fromArray($data));
    }

    public function testThrowsOnInvalidAdminPubkey(): void
    {
        $data = $this->validConfig();
        $data['admin_pubkey'] = 'not-a-hex-key';

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('admin_pubkey');

        RelayConfig::fromValues(ConfigValues::fromArray($data));
    }

    public function testThrowsOnMissingDatabasePath(): void
    {
        $data = $this->validConfig();
        unset($data['database_path']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('database_path');

        RelayConfig::fromValues(ConfigValues::fromArray($data));
    }

    public function testRejectsALogPathBecauseTheLogGoesToStandardOutputOnly(): void
    {
        $data = $this->validConfig();
        $data['log_path'] = '/var/log/relay.log';

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown config key: log_path');

        RelayConfig::fromValues(ConfigValues::fromArray($data));
    }

    public function testThrowsOnInvalidRelayUrl(): void
    {
        $data = $this->validConfig();
        $data['relay_url'] = 'not-a-url';

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('relay_url');

        RelayConfig::fromValues(ConfigValues::fromArray($data));
    }

    /**
     * @return array<string, mixed>
     */
    private function validConfig(): array
    {
        return [
            'admin_pubkey' => str_repeat('aa', 32),
            'host' => '127.0.0.1',
            'port' => 8080,
            'relay_url' => 'wss://relay.example.com',
            'database_path' => '/tmp/test.sqlite',
            'name' => 'Test Relay',
            'description' => 'A test relay',
            'connection_limits' => ['max_connections' => 100],
            'trusted_proxies' => ['127.0.0.1'],
        ];
    }
}
