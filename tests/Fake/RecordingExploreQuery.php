<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Tests\Fake;

use Innis\Hubstr\Relay\Application\Port\ExploreQueryInterface;
use Innis\Hubstr\Relay\Domain\Collection\ExploreEntryCollection;
use Innis\Hubstr\Relay\Domain\Enum\ExplorePeriod;
use Innis\Hubstr\Relay\Domain\Enum\StatName;
use Innis\Hubstr\Relay\Domain\ValueObject\ExploreEntryInterface;
use Override;

final class RecordingExploreQuery implements ExploreQueryInterface
{
    /** @var array<string, list<ExploreEntryInterface>> */
    public array $resultsByStat = [];

    /** @var list<array{StatName, ExplorePeriod, int}> */
    public array $calls = [];

    #[Override]
    public function findByStat(StatName $stat, ExplorePeriod $period, int $limit): ExploreEntryCollection
    {
        $this->calls[] = [$stat, $period, $limit];

        return new ExploreEntryCollection($this->resultsByStat[$stat->value] ?? []);
    }

    public function callCount(StatName $stat): int
    {
        return count(array_filter($this->calls, static fn (array $call) => $call[0] === $stat));
    }
}
