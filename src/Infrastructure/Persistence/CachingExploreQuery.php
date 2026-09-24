<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Infrastructure\Persistence;

use Innis\Hubstr\Relay\Application\Port\ExploreQueryInterface;
use Innis\Hubstr\Relay\Domain\Collection\ExploreEntryCollection;
use Innis\Hubstr\Relay\Domain\Enum\ExplorePeriod;
use Innis\Hubstr\Relay\Domain\Enum\StatName;
use Innis\Hubstr\Relay\Domain\ValueObject\ExploreEntryInterface;
use Innis\Hubstr\Relay\Domain\ValueObject\StatKey;
use Override;

final readonly class CachingExploreQuery implements ExploreQueryInterface
{
    public function __construct(
        private ExploreQueryInterface $underlying,
        private StatResultCache $cache,
        private StatPayloadCodec $codec,
    ) {
    }

    #[Override]
    public function findByStat(StatName $stat, ExplorePeriod $period, int $limit): ExploreEntryCollection
    {
        $key = StatKey::forStat($stat, $period);
        $cached = $this->cache->find($key);

        if (null !== $cached) {
            return $this->slice($this->codec->decodeEntries($stat, $cached), $limit);
        }

        $entries = $this->underlying->findByStat($stat, $period, StatResultsStore::CACHE_LIMIT)->toArray();
        $this->cache->save($key, $this->codec->encodeEntries($entries));

        return $this->slice($entries, $limit);
    }

    /**
     * @param list<ExploreEntryInterface> $entries
     */
    private function slice(array $entries, int $limit): ExploreEntryCollection
    {
        return new ExploreEntryCollection(array_slice($entries, 0, $limit));
    }
}
