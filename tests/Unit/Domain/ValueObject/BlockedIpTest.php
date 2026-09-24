<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Tests\Unit\Domain\ValueObject;

use Innis\Hubstr\Relay\Domain\ValueObject\BlockedIp;
use Innis\Nostr\Relay\Domain\ValueObject\IpAddress;
use PHPUnit\Framework\TestCase;

final class BlockedIpTest extends TestCase
{
    public function testTryFromTrimsTheReason(): void
    {
        $blocked = BlockedIp::tryFromParts(IpAddress::fromString('1.2.3.4'), '  spam  ');

        self::assertSame('spam', $blocked?->getReason());
    }

    public function testTryFromAcceptsAReasonAtTheLimit(): void
    {
        self::assertNotNull(BlockedIp::tryFromParts(IpAddress::fromString('1.2.3.4'), str_repeat('a', BlockedIp::MAX_REASON_LENGTH)));
    }

    public function testTryFromRefusesAReasonBeyondTheLimit(): void
    {
        self::assertNull(BlockedIp::tryFromParts(IpAddress::fromString('1.2.3.4'), str_repeat('a', BlockedIp::MAX_REASON_LENGTH + 1)));
    }

    public function testToArrayCarriesTheAddressAndReason(): void
    {
        self::assertSame(['ip' => '1.2.3.4', 'reason' => 'spam'], BlockedIp::fromParts(IpAddress::fromString('1.2.3.4'), 'spam')->toArray());
    }
}
