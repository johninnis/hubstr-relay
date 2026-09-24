<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Infrastructure\Persistence;

use Innis\Hubstr\Relay\Application\Port\WebOfTrustQueryInterface;
use Innis\Hubstr\Relay\Domain\ValueObject\WebOfTrustScore;
use Innis\Hubstr\Relay\Infrastructure\Worker\Query\ComputeWotScoreQuery;
use Innis\Hubstr\Relay\Infrastructure\Worker\ReadWorkerPool;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use Override;

final readonly class WorkerWebOfTrustQuery implements WebOfTrustQueryInterface
{
    public function __construct(
        private ReadWorkerPool $pool,
    ) {
    }

    #[Override]
    public function score(PublicKey $user, PublicKey $target): WebOfTrustScore
    {
        return $this->pool->queryForInstance(new ComputeWotScoreQuery($user, $target), WebOfTrustScore::class);
    }
}
