<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Tests\Unit\Infrastructure\Auth;

use Innis\Hubstr\Relay\Infrastructure\Auth\InMemoryNip98ReplayGuard;
use Innis\Hubstr\Relay\Tests\Fake\MutableClock;
use Innis\Nostr\Core\Domain\ValueObject\Identity\EventId;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class InMemoryNip98ReplayGuardTest extends TestCase
{
    private const int TTL_SECONDS = 120;

    public function testRecordOnceAcceptsFirstUseAndRejectsReplay(): void
    {
        $guard = new InMemoryNip98ReplayGuard(new MutableClock());
        $eventId = $this->eventId('aa');

        $this->assertTrue($guard->recordOnce($eventId, self::TTL_SECONDS));
        $this->assertFalse($guard->recordOnce($eventId, self::TTL_SECONDS));
    }

    public function testDistinctEventIdsDoNotCollide(): void
    {
        $guard = new InMemoryNip98ReplayGuard(new MutableClock());

        $this->assertTrue($guard->recordOnce($this->eventId('aa'), self::TTL_SECONDS));
        $this->assertTrue($guard->recordOnce($this->eventId('bb'), self::TTL_SECONDS));
    }

    public function testSizeCapEvictsOldestEntry(): void
    {
        $guard = new InMemoryNip98ReplayGuard(new MutableClock(), maxEntries: 2);

        $guard->recordOnce($this->eventId('aa'), 3600);
        $guard->recordOnce($this->eventId('bb'), 3600);
        $guard->recordOnce($this->eventId('cc'), 3600);

        $this->assertTrue($guard->recordOnce($this->eventId('aa'), 3600), 'oldest entry should have been evicted');
    }

    public function testSizeCapPreservesNewerEntries(): void
    {
        $guard = new InMemoryNip98ReplayGuard(new MutableClock(), maxEntries: 2);

        $guard->recordOnce($this->eventId('aa'), 3600);
        $guard->recordOnce($this->eventId('bb'), 3600);
        $guard->recordOnce($this->eventId('cc'), 3600);

        $this->assertFalse($guard->recordOnce($this->eventId('bb'), 3600), 'second entry should still be tracked');
        $this->assertFalse($guard->recordOnce($this->eventId('cc'), 3600), 'newest entry should still be tracked');
    }

    public function testPruneRemovesExpiredEntries(): void
    {
        $clock = new MutableClock();
        $guard = new InMemoryNip98ReplayGuard($clock);
        $eventId = $this->eventId('aa');

        $this->assertTrue($guard->recordOnce($eventId, 1));
        $clock->advance(2);
        $this->assertTrue($guard->recordOnce($eventId, 1), 'expired entry should be re-recordable');
    }

    private function eventId(string $prefix): EventId
    {
        $hex = str_repeat($prefix, 32);
        $id = EventId::tryFromHex(substr($hex, 0, 64));
        if (null === $id) {
            throw new RuntimeException('Invalid hex fixture');
        }

        return $id;
    }
}
