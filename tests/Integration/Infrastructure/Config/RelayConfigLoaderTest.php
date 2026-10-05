<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Tests\Integration\Infrastructure\Config;

use Innis\Hubstr\Core\Application\Port\VersionProviderInterface;
use Innis\Hubstr\Relay\Infrastructure\Config\RelayConfigLoader;
use Innis\Hubstr\Relay\Infrastructure\Persistence\EventQueryStore;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class RelayConfigLoaderTest extends TestCase
{
    private string $configPath;

    protected function setUp(): void
    {
        $this->configPath = tempnam(sys_get_temp_dir(), 'relay-config-') ?: throw new RuntimeException('could not create a temp config file');
    }

    protected function tearDown(): void
    {
        unlink($this->configPath);
    }

    public function testLoadStampsTheRelayInfoWithTheProvidedVersion(): void
    {
        $versionProvider = $this->createStub(VersionProviderInterface::class);
        $versionProvider->method('getVersion')->willReturn('9.9.9-test');

        $config = new RelayConfigLoader()->load($this->writeConfig([]), $versionProvider);

        $this->assertSame('9.9.9-test', $config->getRelayInfo()->getVersion());
    }

    public function testTakesAMaxFilterValuesTheStoreCanBind(): void
    {
        $config = new RelayConfigLoader()->load($this->writeConfig(['limits' => ['max_filter_values' => EventQueryStore::MAX_FILTER_VALUES]]));

        $this->assertSame(EventQueryStore::MAX_FILTER_VALUES, $config->getRelayLimits()->getMaxFilterValues());
    }

    public function testRejectsAMaxFilterValuesAboveWhatTheStoreCanBind(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('limits.max_filter_values must be at most '.EventQueryStore::MAX_FILTER_VALUES);

        new RelayConfigLoader()->load($this->writeConfig(['limits' => ['max_filter_values' => EventQueryStore::MAX_FILTER_VALUES + 1]]));
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function writeConfig(array $overrides): string
    {
        file_put_contents($this->configPath, '<?php return '.var_export($overrides + [
            'admin_pubkey' => str_repeat('aa', 32),
            'relay_url' => 'wss://relay.example.com',
            'database_path' => '/tmp/test.sqlite',
        ], true).';');

        return $this->configPath;
    }
}
