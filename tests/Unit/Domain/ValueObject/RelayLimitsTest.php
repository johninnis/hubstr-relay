<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Tests\Unit\Domain\ValueObject;

use Innis\Hubstr\Relay\Domain\ValueObject\RelayLimits;
use Innis\Nostr\Core\Domain\Collection\FilterCollection;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Filter;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RelayLimitsTest extends TestCase
{
    public function testTheDefaultsAreWithinTheRangeTheRelayCanApply(): void
    {
        $defaults = RelayLimits::defaults();

        $this->assertSame([1000, 5000], [$defaults->getMaxLimit(), $defaults->getMaxFilterValues()]);
    }

    public function testAMaxLimitAboveFiveThousandIsTheRelaysToChoose(): void
    {
        $this->assertSame(10_000, new RelayLimits(20, 5, 10_000, 65536, 5000)->getMaxLimit());
    }

    #[DataProvider('nonPositiveLimits')]
    public function testEveryLimitMustBePositive(int $maxSubscriptions, int $maxFilters, int $maxLimit, int $maxContentLength, int $maxFilterValues, string $named): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('limits.'.$named.' must be a positive integer');

        new RelayLimits($maxSubscriptions, $maxFilters, $maxLimit, $maxContentLength, $maxFilterValues);
    }

    /**
     * @return iterable<string, array{int, int, int, int, int, string}>
     */
    public static function nonPositiveLimits(): iterable
    {
        yield 'no subscriptions' => [0, 5, 1000, 65536, 5000, 'max_subscriptions'];
        yield 'no filters' => [20, 0, 1000, 65536, 5000, 'max_filters'];
        yield 'a limit that reads nothing' => [20, 5, 0, 65536, 5000, 'max_limit'];
        yield 'a negative limit' => [20, 5, -1, 65536, 5000, 'max_limit'];
        yield 'no content' => [20, 5, 1000, 0, 5000, 'max_content_length'];
        yield 'no filter values' => [20, 5, 1000, 65536, 0, 'max_filter_values'];
    }

    public function testTheSubscriptionLimitsCarryTheSameCeilings(): void
    {
        $limits = new RelayLimits(2, 1, 50, 65536, 1)->toSubscriptionLimits();

        $this->assertNotNull($limits->enforce(2, new FilterCollection()));
        $this->assertNotNull($limits->refuseOversizedFilters(new FilterCollection([Filter::tryFromArray(['kinds' => [1, 2]]) ?? self::fail('filter did not parse')])));
    }

    public function testTheEventLimitsCarryTheConfiguredContentLength(): void
    {
        $limits = new RelayLimits(2, 1, 50, 100_000, 1)->toEventLimits();

        $this->assertSame([true, false], [$limits->admitsContentLength(100_000), $limits->admitsContentLength(100_001)]);
    }
}
