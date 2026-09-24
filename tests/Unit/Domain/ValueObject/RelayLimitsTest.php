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
        $this->assertSame(1000, RelayLimits::defaults()->getMaxLimit());
    }

    #[DataProvider('maxLimitsOutsideTheFilterRange')]
    public function testAMaxLimitAFilterCannotCarryIsRefused(int $maxLimit): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('limits.max_limit');

        new RelayLimits(20, 5, $maxLimit, 65536);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function maxLimitsOutsideTheFilterRange(): iterable
    {
        yield 'zero reads nothing' => [0];
        yield 'negative' => [-1];
        yield 'above what a filter accepts' => [Filter::MAX_LIMIT + 1];
    }

    #[DataProvider('nonPositiveLimits')]
    public function testEveryOtherLimitMustBePositive(int $maxSubscriptions, int $maxFilters, int $maxContentLength, string $named): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('limits.'.$named.' must be a positive integer');

        new RelayLimits($maxSubscriptions, $maxFilters, 1000, $maxContentLength);
    }

    /**
     * @return iterable<string, array{int, int, int, string}>
     */
    public static function nonPositiveLimits(): iterable
    {
        yield 'no subscriptions' => [0, 5, 65536, 'max_subscriptions'];
        yield 'no filters' => [20, 0, 65536, 'max_filters'];
        yield 'no content' => [20, 5, 0, 'max_content_length'];
    }

    public function testTheSubscriptionLimitsCarryTheSameCeilings(): void
    {
        $limits = new RelayLimits(2, 1, 50, 65536);

        $rejection = $limits->toSubscriptionLimits()->enforce(2, new FilterCollection());

        $this->assertNotNull($rejection);
    }
}
