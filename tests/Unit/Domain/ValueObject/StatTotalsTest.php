<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Tests\Unit\Domain\ValueObject;

use Innis\Hubstr\Relay\Domain\ValueObject\StatTotals;
use Innis\Hubstr\Relay\Tests\Support\StatTotalsMother;
use PHPUnit\Framework\TestCase;

final class StatTotalsTest extends TestCase
{
    public function testToArrayAndFromArrayRoundTripPreservesEveryField(): void
    {
        $totals = StatTotalsMother::populated();

        $restored = StatTotals::tryFromArray($totals->toArray());

        self::assertNotNull($restored);
        self::assertSame($totals->toArray(), $restored->toArray());
    }

    public function testTryFromArrayDefaultsMissingFieldsToZero(): void
    {
        $totals = StatTotals::tryFromArray([]);

        self::assertNotNull($totals);
        self::assertSame(0, $totals->toArray()['events']);
        self::assertSame([], $totals->toArray()['events_by_kind']);
    }

    public function testTryFromArrayRejectsNonIntegerCount(): void
    {
        self::assertNull(StatTotals::tryFromArray(['events' => 'many']));
    }

    public function testToArrayKeepsTheWireShape(): void
    {
        self::assertSame(
            [
                'events' => 10,
                'tags' => 20,
                'follows' => 5,
                'mutes' => 3,
                'relays' => 2,
                'zaps' => 7,
                'known_pubkeys' => 8,
                'events_by_kind' => [['kind' => 1, 'count' => 4], ['kind' => 7, 'count' => 1]],
                'events_by_tenant' => [['pubkey' => str_repeat('aa', 32), 'count' => 9]],
            ],
            StatTotalsMother::populated()->toArray(),
        );
    }

    public function testTryFromArrayRejectsAMalformedKindBreakdown(): void
    {
        self::assertNull(StatTotals::tryFromArray(['events_by_kind' => [['kind' => 'one', 'count' => 4]]]));
    }

    public function testTryFromArrayRejectsAMalformedTenantBreakdown(): void
    {
        self::assertNull(StatTotals::tryFromArray(['events_by_tenant' => [['pubkey' => 'abc', 'count' => 9]]]));
    }

    public function testTryFromArrayRejectsABreakdownThatIsNotAList(): void
    {
        self::assertNull(StatTotals::tryFromArray(['events_by_kind' => 'none']));
    }
}
