<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Tests\Support;

use Innis\Hubstr\Relay\Domain\Collection\KindCountCollection;
use Innis\Hubstr\Relay\Domain\Collection\PubkeyCountCollection;
use Innis\Hubstr\Relay\Domain\Enum\StatMetric;
use Innis\Hubstr\Relay\Domain\ValueObject\KindCount;
use Innis\Hubstr\Relay\Domain\ValueObject\MetricCounts;
use Innis\Hubstr\Relay\Domain\ValueObject\PubkeyCount;
use Innis\Hubstr\Relay\Domain\ValueObject\StatTotals;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventKind;

final class StatTotalsMother
{
    public static function withEvents(int $events): StatTotals
    {
        return new StatTotals(
            MetricCounts::forEachMetric(static fn (StatMetric $metric): int => StatMetric::Events === $metric ? $events : 0),
            new KindCountCollection(),
            new PubkeyCountCollection(),
        );
    }

    public static function populated(): StatTotals
    {
        return new StatTotals(
            MetricCounts::forEachMetric(static fn (StatMetric $metric): int => match ($metric) {
                StatMetric::Events => 10,
                StatMetric::Tags => 20,
                StatMetric::Follows => 5,
                StatMetric::Mutes => 3,
                StatMetric::Relays => 2,
                StatMetric::Zaps => 7,
                StatMetric::KnownPubkeys => 8,
            }),
            new KindCountCollection([
                new KindCount(EventKind::fromInt(EventKind::TEXT_NOTE), 4),
                new KindCount(EventKind::fromInt(EventKind::REACTION), 1),
            ]),
            new PubkeyCountCollection([new PubkeyCount(SignedEventFactory::pubkey('aa'), 9)]),
        );
    }
}
