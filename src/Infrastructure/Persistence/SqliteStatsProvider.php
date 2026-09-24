<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Infrastructure\Persistence;

use Innis\Hubstr\Relay\Application\Port\StatsProviderInterface;
use Innis\Hubstr\Relay\Domain\Collection\KindCountCollection;
use Innis\Hubstr\Relay\Domain\Collection\PubkeyCountCollection;
use Innis\Hubstr\Relay\Domain\Enum\StatMetric;
use Innis\Hubstr\Relay\Domain\ValueObject\MetricCounts;
use Innis\Hubstr\Relay\Domain\ValueObject\StatTotals;
use Override;
use PDO;

final readonly class SqliteStatsProvider implements StatsProviderInterface
{
    public function __construct(
        private PDO $pdo,
    ) {
    }

    #[Override]
    public function getStats(): StatTotals
    {
        return new StatTotals(
            MetricCounts::forEachMetric($this->count(...)),
            KindCountCollection::fromRows($this->fetchAssoc('SELECT kind, COUNT(*) AS count FROM events GROUP BY kind ORDER BY count DESC')),
            PubkeyCountCollection::fromRows($this->fetchAssoc(
                'SELECT LOWER(HEX(t.pubkey)) AS pubkey, COUNT(e.event_id) AS count
                 FROM tenants t
                 LEFT JOIN events e ON e.pubkey = t.pubkey
                 GROUP BY t.pubkey
                 ORDER BY count DESC'
            )),
        );
    }

    private function count(StatMetric $metric): int
    {
        $stmt = $this->pdo->prepare(match ($metric) {
            StatMetric::Events => 'SELECT COUNT(*) FROM events',
            StatMetric::Tags => 'SELECT COUNT(*) FROM event_tags',
            StatMetric::Follows => 'SELECT COUNT(*) FROM profile_follows',
            StatMetric::Mutes => 'SELECT COUNT(*) FROM profile_mutes',
            StatMetric::Relays => 'SELECT COUNT(*) FROM profile_relays',
            StatMetric::Zaps => 'SELECT COUNT(*) FROM zap_receipts',
            StatMetric::KnownPubkeys => 'SELECT COUNT(DISTINCT pubkey) FROM events',
        });
        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }

    /**
     * @return array<array-key, mixed>
     */
    private function fetchAssoc(string $sql): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
