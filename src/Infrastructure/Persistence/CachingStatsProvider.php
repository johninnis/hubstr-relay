<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Infrastructure\Persistence;

use Innis\Hubstr\Relay\Application\Port\StatsProviderInterface;
use Innis\Hubstr\Relay\Domain\ValueObject\StatKey;
use Innis\Hubstr\Relay\Domain\ValueObject\StatTotals;
use Override;

final readonly class CachingStatsProvider implements StatsProviderInterface
{
    public function __construct(
        private StatsProviderInterface $underlying,
        private StatResultCache $cache,
        private StatPayloadCodec $codec,
    ) {
    }

    #[Override]
    public function getStats(): StatTotals
    {
        $key = StatKey::totals();
        $cached = $this->cache->find($key);

        if (null !== $cached) {
            return $this->codec->decodeTotals($cached);
        }

        $stats = $this->underlying->getStats();
        $this->cache->save($key, $this->codec->encodeTotals($stats));

        return $stats;
    }
}
