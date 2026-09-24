<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Tests\Unit\Application\DTO;

use Innis\Hubstr\Relay\Application\DTO\ActiveSubscriptionSnapshot;
use Innis\Hubstr\Relay\Application\DTO\SubscriptionSnapshot;
use Innis\Nostr\Core\Domain\Collection\FilterCollection;
use Innis\Nostr\Core\Domain\Enum\SubscriptionState;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Filter;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\SubscriptionId;
use Innis\Nostr\Core\Domain\ValueObject\Timestamp;
use Innis\Nostr\Relay\Domain\Entity\RelayClient;
use Innis\Nostr\Relay\Domain\ValueObject\ClientId;
use Innis\Nostr\Relay\Domain\ValueObject\ConnectionInfo;
use Innis\Nostr\Relay\Domain\ValueObject\IpAddress;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ActiveSubscriptionSnapshotTest extends TestCase
{
    public function testToArrayExposesClientContextAndSerialisedFilters(): void
    {
        $filter = Filter::tryFromArray(['kinds' => [1]]) ?? throw new RuntimeException('Invalid filter');
        $client = new RelayClient(
            ClientId::fromString('abc123'),
            new ConnectionInfo(IpAddress::fromString('192.168.1.1'), 'test-client', Timestamp::fromInt(1700000000)),
        );
        $subscription = new SubscriptionSnapshot(
            SubscriptionId::tryFromString('sub1') ?? throw new RuntimeException('Invalid subscription id'),
            SubscriptionState::Active,
            new FilterCollection([$filter]),
        );

        self::assertSame(
            [
                'client_id' => 'abc123',
                'client_ip' => '192.168.1.1',
                'subscription_id' => 'sub1',
                'state' => 'active',
                'filters' => [$filter->toArray()],
            ],
            new ActiveSubscriptionSnapshot($client, $subscription)->toArray(),
        );
    }
}
