<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Tests\Unit\Application\DTO;

use Innis\Hubstr\Relay\Application\DTO\SubscriptionSnapshot;
use Innis\Nostr\Core\Domain\Collection\FilterCollection;
use Innis\Nostr\Core\Domain\Enum\SubscriptionState;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Filter;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\SubscriptionId;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SubscriptionSnapshotTest extends TestCase
{
    public function testToArraySerialisesEveryFilter(): void
    {
        $filter = Filter::tryFromArray(['kinds' => [1]]) ?? throw new RuntimeException('Invalid filter');
        $subscriptionId = SubscriptionId::tryFromString('sub1') ?? throw new RuntimeException('Invalid subscription id');
        $snapshot = new SubscriptionSnapshot($subscriptionId, SubscriptionState::Active, new FilterCollection([$filter]));

        self::assertSame(
            [
                'id' => 'sub1',
                'state' => 'active',
                'filters' => [$filter->toArray()],
            ],
            $snapshot->toArray(),
        );
    }

    public function testToArrayWithoutFiltersYieldsAnEmptyList(): void
    {
        $subscriptionId = SubscriptionId::tryFromString('sub1') ?? throw new RuntimeException('Invalid subscription id');
        $snapshot = new SubscriptionSnapshot($subscriptionId, SubscriptionState::ClosedByClient, new FilterCollection());

        self::assertSame([], $snapshot->toArray()['filters']);
    }
}
