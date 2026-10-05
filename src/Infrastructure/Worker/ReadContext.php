<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Infrastructure\Worker;

use Innis\Hubstr\Relay\Infrastructure\Persistence\EventQueryStore;
use Innis\Hubstr\Relay\Infrastructure\Persistence\SqliteExploreQuery;
use Innis\Hubstr\Relay\Infrastructure\Persistence\SqliteStatsProvider;
use Innis\Hubstr\Relay\Infrastructure\Persistence\SqliteWebOfTrustQuery;
use PDO;

final class ReadContext
{
    private ?EventQueryStore $eventQueryStore = null;
    private ?SqliteStatsProvider $statsProvider = null;
    private ?SqliteExploreQuery $exploreQuery = null;
    private ?SqliteWebOfTrustQuery $webOfTrustQuery = null;

    private function __construct(
        private readonly PDO $pdo,
    ) {
    }

    public static function forConnection(PDO $pdo): self
    {
        return new self($pdo);
    }

    public function getEventQueryStore(): EventQueryStore
    {
        return $this->eventQueryStore ??= new EventQueryStore($this->pdo);
    }

    public function getStatsProvider(): SqliteStatsProvider
    {
        return $this->statsProvider ??= new SqliteStatsProvider($this->pdo);
    }

    public function getExploreQuery(): SqliteExploreQuery
    {
        return $this->exploreQuery ??= new SqliteExploreQuery($this->pdo);
    }

    public function getWebOfTrustQuery(): SqliteWebOfTrustQuery
    {
        return $this->webOfTrustQuery ??= new SqliteWebOfTrustQuery($this->pdo);
    }
}
