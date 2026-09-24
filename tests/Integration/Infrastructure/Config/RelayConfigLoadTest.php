<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Tests\Integration\Infrastructure\Config;

use Innis\Hubstr\Core\Application\Port\VersionProviderInterface;
use Innis\Hubstr\Relay\Infrastructure\Config\RelayConfig;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class RelayConfigLoadTest extends TestCase
{
    private string $configPath;

    protected function setUp(): void
    {
        $this->configPath = tempnam(sys_get_temp_dir(), 'relay-config-') ?: throw new RuntimeException('could not create a temp config file');
        file_put_contents($this->configPath, '<?php return '.var_export([
            'admin_pubkey' => str_repeat('aa', 32),
            'relay_url' => 'wss://relay.example.com',
            'database_path' => '/tmp/test.sqlite',
        ], true).';');
    }

    protected function tearDown(): void
    {
        unlink($this->configPath);
    }

    public function testLoadStampsTheRelayInfoWithTheProvidedVersion(): void
    {
        $versionProvider = $this->createStub(VersionProviderInterface::class);
        $versionProvider->method('getVersion')->willReturn('9.9.9-test');

        $config = RelayConfig::load($this->configPath, $versionProvider);

        $this->assertSame('9.9.9-test', $config->getRelayInfo()->getVersion());
    }
}
