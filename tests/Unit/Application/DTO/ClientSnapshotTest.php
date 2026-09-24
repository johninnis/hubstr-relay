<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Tests\Unit\Application\DTO;

use Innis\Hubstr\Relay\Application\Collection\SubscriptionSnapshotCollection;
use Innis\Hubstr\Relay\Application\DTO\ClientSnapshot;
use Innis\Hubstr\Relay\Application\DTO\SubscriptionSnapshot;
use Innis\Nostr\Core\Domain\Collection\FilterCollection;
use Innis\Nostr\Core\Domain\Enum\SubscriptionState;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\SubscriptionId;
use Innis\Nostr\Core\Domain\ValueObject\Timestamp;
use Innis\Nostr\Relay\Domain\Entity\RelayClient;
use Innis\Nostr\Relay\Domain\ValueObject\ClientId;
use Innis\Nostr\Relay\Domain\ValueObject\ConnectionInfo;
use Innis\Nostr\Relay\Domain\ValueObject\IpAddress;
use Innis\Nostr\Relay\Domain\ValueObject\SessionCounters;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ClientSnapshotTest extends TestCase
{
    public function testToArrayExposesEveryFieldAndNestsSubscriptions(): void
    {
        $subscriptionId = SubscriptionId::tryFromString('sub1') ?? throw new RuntimeException('Invalid subscription id');
        $subscription = new SubscriptionSnapshot($subscriptionId, SubscriptionState::Active, new FilterCollection());
        $client = new RelayClient(
            ClientId::fromString('abc123'),
            new ConnectionInfo(IpAddress::fromString('192.168.1.1'), 'test-client', Timestamp::fromInt(1700000000)),
        );
        $snapshot = new ClientSnapshot($client, new SessionCounters(5, 4, 12), new SubscriptionSnapshotCollection([$subscription]));

        self::assertSame(
            [
                'id' => 'abc123',
                'ip' => '192.168.1.1',
                'user_agent' => 'test-client',
                'connected_at' => 1700000000,
                'events_received' => 5,
                'events_accepted' => 4,
                'events_sent' => 12,
                'subscriptions' => [$subscription->toArray()],
            ],
            $snapshot->toArray(),
        );
    }
}
