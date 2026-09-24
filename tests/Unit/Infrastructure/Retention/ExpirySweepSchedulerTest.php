<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Tests\Unit\Infrastructure\Retention;

use Innis\Hubstr\Relay\Application\Port\EventPurgerInterface;
use Innis\Hubstr\Relay\Infrastructure\Retention\ExpirySweepScheduler;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

final class ExpirySweepSchedulerTest extends TestCase
{
    public function testATickAsksThePurgerToRemoveExpiredEvents(): void
    {
        $purger = $this->createMock(EventPurgerInterface::class);
        $purger->expects(self::once())->method('purgeExpired');

        new ExpirySweepScheduler($purger, new NullLogger())->tick();
    }

    public function testASweepThatFailsIsLoggedRatherThanRaised(): void
    {
        $fault = new RuntimeException('write worker gone');
        $purger = $this->createStub(EventPurgerInterface::class);
        $purger->method('purgeExpired')->willThrowException($fault);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')->with('Expired event sweep failed', ['exception' => $fault]);

        new ExpirySweepScheduler($purger, $logger)->tick();
    }

    public function testDrainingBeforeStartingIsSafe(): void
    {
        $scheduler = new ExpirySweepScheduler($this->createStub(EventPurgerInterface::class), new NullLogger());

        $scheduler->drain();

        $this->expectNotToPerformAssertions();
    }

    public function testStoppingKillsNothingOfItsOwn(): void
    {
        $purger = $this->createMock(EventPurgerInterface::class);
        $purger->expects(self::never())->method('purgeExpired');

        new ExpirySweepScheduler($purger, new NullLogger())->stop();
    }
}
