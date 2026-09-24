<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Tests\Integration\Relay;

use Innis\Hubstr\Relay\Tests\Support\HostileSessionChurn;
use PHPUnit\Framework\TestCase;

final class HostileSessionChurnTest extends TestCase
{
    public function testOnlyADroppedPeerEverEscapesASessionOperation(): void
    {
        self::assertSame([], HostileSessionChurn::outcome()->getEscapedFaults());
    }

    public function testNoClientIsEverToldOfAnInternalServerError(): void
    {
        self::assertSame(0, HostileSessionChurn::outcome()->getInternalErrorNotices());
    }

    public function testTheRegisteredClientCountNeverExceedsTheConfiguredMaximum(): void
    {
        self::assertLessThanOrEqual(HostileSessionChurn::MAX_CONNECTIONS, HostileSessionChurn::outcome()->getMostClientsRegistered());
    }

    public function testEveryClientIsGoneAfterTeardown(): void
    {
        self::assertSame(0, HostileSessionChurn::outcome()->getClientsLeft());
    }

    public function testEverySubscriptionIsGoneAfterTeardown(): void
    {
        self::assertSame(0, HostileSessionChurn::outcome()->getSubscriptionsLeft());
    }

    public function testTheMetricsCountersReturnToZeroAfterTeardown(): void
    {
        $metrics = HostileSessionChurn::outcome()->getMetricsAfterTeardown();

        self::assertSame([0, 0], [$metrics->getActiveConnections(), $metrics->getTotalSubscriptions()]);
    }
}
