<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Presentation\Http\Rpc;

use Innis\Hubstr\Relay\Application\Port\ExploreQueryInterface;
use Innis\Hubstr\Relay\Application\Port\StatsProviderInterface;
use Innis\Hubstr\Relay\Application\Port\WebOfTrustQueryInterface;
use Innis\Hubstr\Relay\Domain\Enum\ExplorePeriod;
use Innis\Hubstr\Relay\Domain\Enum\HubstrRpcMethod;
use Innis\Hubstr\Relay\Domain\Enum\StatName;
use Innis\Hubstr\Relay\Domain\ValueObject\ExploreEntryInterface;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use Override;

final readonly class InsightsRpcHandler implements RpcMethodHandlerInterface
{
    private const int DEFAULT_EXPLORE_LIMIT = 20;
    private const int MAX_EXPLORE_LIMIT = 100;

    public function __construct(
        private StatsProviderInterface $statsProvider,
        private ExploreQueryInterface $exploreQuery,
        private WebOfTrustQueryInterface $webOfTrustQuery,
    ) {
    }

    #[Override]
    public function handlers(): array
    {
        return [
            HubstrRpcMethod::GetStats->value => $this->getStats(...),
            HubstrRpcMethod::Explore->value => $this->explore(...),
            HubstrRpcMethod::GetWotScore->value => $this->getWotScore(...),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function getStats(): array
    {
        return $this->statsProvider->getStats()->toArray();
    }

    /**
     * @return array<string, mixed>|RpcRejection
     */
    private function explore(RpcParams $params): array|RpcRejection
    {
        $periodString = $params->string(0) ?? ExplorePeriod::All->value;
        $period = ExplorePeriod::tryFrom($periodString);

        if (null === $period) {
            return RpcRejection::badRequest("Invalid period: {$periodString}. Use: 24h, 7d, 30d, all");
        }

        $limit = max(1, min($params->int(1) ?? self::DEFAULT_EXPLORE_LIMIT, self::MAX_EXPLORE_LIMIT));

        $results = [];
        foreach (StatName::cases() as $stat) {
            $results[$stat->value] = array_map(
                static fn (ExploreEntryInterface $entry): array => $entry->toArray(),
                $this->exploreQuery->findByStat($stat, $period, $limit)->toArray(),
            );
        }

        return [
            'period' => $period->value,
            'data' => $results,
        ];
    }

    /**
     * @return array<string, mixed>|RpcRejection
     */
    private function getWotScore(RpcParams $params, PublicKey $user): array|RpcRejection
    {
        $target = $params->pubkey();

        return null === $target
            ? RpcRejection::invalidPubkey()
            : $this->webOfTrustQuery->score($user, $target)->toArray();
    }
}
