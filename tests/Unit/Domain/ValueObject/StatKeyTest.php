<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Tests\Unit\Domain\ValueObject;

use Innis\Hubstr\Relay\Domain\Enum\ExplorePeriod;
use Innis\Hubstr\Relay\Domain\Enum\StatName;
use Innis\Hubstr\Relay\Domain\ValueObject\StatKey;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StatKeyTest extends TestCase
{
    #[DataProvider('stats')]
    public function testAStatKeyTakesItsNameFromTheStat(StatName $stat): void
    {
        self::assertSame($stat->value, StatKey::forStat($stat, ExplorePeriod::Day)->getName());
    }

    public function testAStatKeyCarriesThePeriodItWasBuiltFor(): void
    {
        self::assertSame(ExplorePeriod::Week, StatKey::forStat(StatName::MostFollowed, ExplorePeriod::Week)->getPeriod());
    }

    public function testTheTotalsKeyIsAlwaysTheAllTimePeriod(): void
    {
        self::assertSame(ExplorePeriod::All, StatKey::totals()->getPeriod());
    }

    #[DataProvider('stats')]
    public function testTheTotalsKeyCannotCollideWithAStat(StatName $stat): void
    {
        self::assertNotSame($stat->value, StatKey::totals()->getName());
    }

    /**
     * @return iterable<string, array{StatName}>
     */
    public static function stats(): iterable
    {
        foreach (StatName::cases() as $stat) {
            yield $stat->value => [$stat];
        }
    }
}
