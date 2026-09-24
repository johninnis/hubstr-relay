<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Infrastructure\Worker;

use Innis\Hubstr\Relay\Infrastructure\Persistence\EventQueryStore;
use Innis\Hubstr\Relay\Infrastructure\Persistence\SqliteExploreQuery;
use Innis\Hubstr\Relay\Infrastructure\Persistence\SqliteStatsProvider;
use Innis\Hubstr\Relay\Infrastructure\Persistence\SqliteWebOfTrustQuery;
use PDO;

final readonly class ReadContext
{
    public function __construct(
        private EventQueryStore $eventQueryStore,
        private SqliteStatsProvider $statsProvider,
        private SqliteExploreQuery $exploreQuery,
        private SqliteWebOfTrustQuery $webOfTrustQuery,
    ) {
    }

    public static function forConnection(PDO $pdo): self
    {
        return new self(
            new EventQueryStore($pdo),
            new SqliteStatsProvider($pdo),
            new SqliteExploreQuery($pdo),
            new SqliteWebOfTrustQuery($pdo),
        );
    }

    public function getEventQueryStore(): EventQueryStore
    {
        return $this->eventQueryStore;
    }

    public function getStatsProvider(): SqliteStatsProvider
    {
        return $this->statsProvider;
    }

    public function getExploreQuery(): SqliteExploreQuery
    {
        return $this->exploreQuery;
    }

    public function getWebOfTrustQuery(): SqliteWebOfTrustQuery
    {
        return $this->webOfTrustQuery;
    }
}
