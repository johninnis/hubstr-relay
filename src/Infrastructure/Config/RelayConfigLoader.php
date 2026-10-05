<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Infrastructure\Config;

use Innis\Hubstr\Core\Application\Port\VersionProviderInterface;
use Innis\Hubstr\Core\Infrastructure\Config\ConfigLoader;
use Innis\Hubstr\Core\Infrastructure\Version\ComposerVersionProvider;
use Innis\Hubstr\Relay\Application\DTO\RelayConfig;
use Innis\Hubstr\Relay\Infrastructure\Persistence\EventQueryStore;
use InvalidArgumentException;

final readonly class RelayConfigLoader
{
    private const string ENVIRONMENT_VARIABLE = 'HUBSTR_RELAY_CONFIG';

    public function load(string $configPath, VersionProviderInterface $versionProvider = new ComposerVersionProvider()): RelayConfig
    {
        $config = RelayConfig::fromValues(new ConfigLoader(self::ENVIRONMENT_VARIABLE)->load($configPath), $versionProvider->getVersion());

        $maxFilterValues = $config->getRelayLimits()->getMaxFilterValues();

        if ($maxFilterValues > EventQueryStore::MAX_FILTER_VALUES) {
            throw new InvalidArgumentException(sprintf('limits.max_filter_values must be at most %d, the values one SQLite query can bind, got %d', EventQueryStore::MAX_FILTER_VALUES, $maxFilterValues));
        }

        return $config;
    }
}
