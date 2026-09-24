<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Tests\Integration\Infrastructure\Worker;

use Innis\Hubstr\Relay\Domain\Exception\WorkerResultException;
use Innis\Hubstr\Relay\Infrastructure\Worker\Command\AddTenantCommand;
use Innis\Hubstr\Relay\Infrastructure\Worker\Command\DeleteEventIdsCommand;
use Innis\Hubstr\Relay\Infrastructure\Worker\Command\DeletePubkeyChunkCommand;
use Innis\Hubstr\Relay\Infrastructure\Worker\Command\PurgeExpiredChunkCommand;
use Innis\Hubstr\Relay\Infrastructure\Worker\Command\StoreEventsCommand;
use Innis\Hubstr\Relay\Infrastructure\Worker\WorkerFailure;
use Innis\Hubstr\Relay\Infrastructure\Worker\WriteCoordinator;
use Innis\Hubstr\Relay\Tests\Fake\QueueChannel;
use Innis\Hubstr\Relay\Tests\Support\SignedEventFactory;
use Innis\Nostr\Core\Domain\Collection\EventIdCollection;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventKind;
use Innis\Nostr\Core\Domain\ValueObject\Identity\EventId;
use Innis\Nostr\Core\Domain\ValueObject\Identity\KeyPair;
use Innis\Nostr\Relay\Domain\Enum\EventStoreOutcome;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;

use function Amp\async;
use function Amp\delay;

final class WriteCoordinatorTest extends TestCase
{
    private KeyPair $keyPair;

    protected function setUp(): void
    {
        $this->keyPair = KeyPair::generate(SignedEventFactory::signer());
    }

    public function testStoreDispatchesCommandAndReturnsOutcome(): void
    {
        $event = SignedEventFactory::signedEvent($this->keyPair, EventKind::fromInt(EventKind::TEXT_NOTE), 'hello');
        $channel = new QueueChannel([[EventStoreOutcome::Stored]]);
        $coordinator = new WriteCoordinator($channel);

        $outcome = async(static fn () => $coordinator->store($event))->await();

        self::assertSame(EventStoreOutcome::Stored, $outcome);
        self::assertCount(1, $channel->sent);
        self::assertInstanceOf(StoreEventsCommand::class, $channel->sent[0]);
    }

    public function testDeleteByEventIdsDispatchesCommandAndReturnsCount(): void
    {
        $author = SignedEventFactory::pubkey('aa');
        $eventId = EventId::tryFromHex(str_repeat('bb', 32)) ?? self::fail('Invalid event id');
        $channel = new QueueChannel([5]);
        $coordinator = new WriteCoordinator($channel);

        $deleted = async(static fn () => $coordinator->applyForInt(new DeleteEventIdsCommand(new EventIdCollection([$eventId]), $author)))->await();

        self::assertSame(5, $deleted);
        self::assertInstanceOf(DeleteEventIdsCommand::class, $channel->sent[0]);
    }

    public function testStoreThrowsOnUnexpectedResult(): void
    {
        $event = SignedEventFactory::signedEvent($this->keyPair, EventKind::fromInt(EventKind::TEXT_NOTE), 'hello');
        $coordinator = new WriteCoordinator(new QueueChannel(['not-an-array']));

        $this->expectException(WorkerResultException::class);

        async(static fn () => $coordinator->store($event))->await();
    }

    public function testDeleteThrowsOnUnexpectedResult(): void
    {
        $author = SignedEventFactory::pubkey('aa');
        $coordinator = new WriteCoordinator(new QueueChannel(['not-an-int']));

        $this->expectException(WorkerResultException::class);

        async(static fn () => $coordinator->applyForInt(new DeleteEventIdsCommand(new EventIdCollection([]), $author)))->await();
    }

    public function testStoreErrorsWhenChannelClosedInsteadOfHanging(): void
    {
        $event = SignedEventFactory::signedEvent($this->keyPair, EventKind::fromInt(EventKind::TEXT_NOTE), 'hello');
        $channel = new QueueChannel();
        $channel->close();
        $coordinator = new WriteCoordinator($channel);

        $this->expectException(WorkerResultException::class);
        $this->expectExceptionMessage('Write worker channel closed');

        async(static fn () => $coordinator->store($event))->await();
    }

    public function testPendingFuturesFailWhenChannelClosesBeforeDrain(): void
    {
        $author = SignedEventFactory::pubkey('aa');
        $channel = new QueueChannel();
        $channel->close();
        $coordinator = new WriteCoordinator($channel);

        $this->expectException(WorkerResultException::class);
        $this->expectExceptionMessage('Write worker channel closed');

        async(static fn () => $coordinator->applyForInt(new DeleteEventIdsCommand(new EventIdCollection([]), $author)))->await();
    }

    public function testWorkerFailureErrorsThatStoreButKeepsTheWritePathAlive(): void
    {
        $event = SignedEventFactory::signedEvent($this->keyPair, EventKind::fromInt(EventKind::TEXT_NOTE), 'hello');
        $channel = new QueueChannel([new WorkerFailure('disk full'), [EventStoreOutcome::Stored]]);
        $coordinator = new WriteCoordinator($channel);

        try {
            async(static fn () => $coordinator->store($event))->await();
            self::fail('expected the worker failure to surface');
        } catch (WorkerResultException $e) {
            self::assertStringContainsString('disk full', $e->getMessage());
        }

        $outcome = async(static fn () => $coordinator->store($event))->await();

        self::assertSame(EventStoreOutcome::Stored, $outcome);
    }

    public function testConcurrentStoresCoalesceIntoOneBatch(): void
    {
        $first = SignedEventFactory::signedEvent($this->keyPair, EventKind::fromInt(EventKind::TEXT_NOTE), 'first');
        $second = SignedEventFactory::signedEvent($this->keyPair, EventKind::fromInt(EventKind::TEXT_NOTE), 'second');
        $channel = new QueueChannel([[EventStoreOutcome::Stored, EventStoreOutcome::Duplicate]]);
        $coordinator = new WriteCoordinator($channel);

        $outcomes = async(static function () use ($coordinator, $first, $second): array {
            $a = async(static fn () => $coordinator->store($first));
            $b = async(static fn () => $coordinator->store($second));

            return [$a->await(), $b->await()];
        })->await();

        self::assertSame([EventStoreOutcome::Stored, EventStoreOutcome::Duplicate], $outcomes);
        self::assertCount(1, $channel->sent);

        $command = $channel->sent[0];
        self::assertInstanceOf(StoreEventsCommand::class, $command);
        self::assertCount(2, $command);
    }

    public function testPoisonEventInBatchOnlyFailsItsOwnSubmission(): void
    {
        $first = SignedEventFactory::signedEvent($this->keyPair, EventKind::fromInt(EventKind::TEXT_NOTE), 'good');
        $second = SignedEventFactory::signedEvent($this->keyPair, EventKind::fromInt(EventKind::TEXT_NOTE), 'poison');
        $channel = new QueueChannel([[EventStoreOutcome::Stored, new WorkerFailure('constraint failed')]]);
        $coordinator = new WriteCoordinator($channel);

        async(static function () use ($coordinator, $first, $second): void {
            $good = async(static fn () => $coordinator->store($first));
            $poison = async(static function () use ($coordinator, $second): string {
                try {
                    $coordinator->store($second);

                    return 'no-error';
                } catch (WorkerResultException $e) {
                    return $e->getMessage();
                }
            });

            self::assertSame(EventStoreOutcome::Stored, $good->await());

            $message = $poison->await();
            self::assertIsString($message);
            self::assertStringContainsString('constraint failed', $message);
        })->await();

        self::assertCount(1, $channel->sent);
    }

    public function testApplyDispatchesCommand(): void
    {
        $channel = new QueueChannel([null]);
        $coordinator = new WriteCoordinator($channel);

        async(static fn () => $coordinator->apply(new AddTenantCommand(SignedEventFactory::pubkey('aa'))))->await();

        self::assertCount(1, $channel->sent);
        self::assertInstanceOf(AddTenantCommand::class, $channel->sent[0]);
    }

    public function testBanDeletionRunsChunkedUntilExhausted(): void
    {
        $channel = new QueueChannel([WriteCoordinator::CHUNK_SIZE, 7]);
        $handler = new TestHandler();
        $coordinator = new WriteCoordinator($channel, new Logger('test', [$handler]));

        async(static function () use ($coordinator): void {
            $coordinator->purgeByPubkey(SignedEventFactory::pubkey('aa'));
            delay(0);
        })->await();

        self::assertCount(2, $channel->sent);
        self::assertInstanceOf(DeletePubkeyChunkCommand::class, $channel->sent[0]);
        self::assertInstanceOf(DeletePubkeyChunkCommand::class, $channel->sent[1]);
        self::assertTrue($handler->hasInfoThatContains('Chunked deletion complete'));
    }

    public function testTheExpirySweepRunsChunkedUntilExhausted(): void
    {
        $channel = new QueueChannel([WriteCoordinator::CHUNK_SIZE, 3]);
        $handler = new TestHandler();
        $coordinator = new WriteCoordinator($channel, new Logger('test', [$handler]));

        async(static function () use ($coordinator): void {
            $coordinator->purgeExpired();
            delay(0);
        })->await();

        self::assertCount(2, $channel->sent);
        self::assertInstanceOf(PurgeExpiredChunkCommand::class, $channel->sent[0]);
        self::assertInstanceOf(PurgeExpiredChunkCommand::class, $channel->sent[1]);
        self::assertTrue($handler->hasInfoThatContains('Chunked deletion complete'));
    }

    public function testASecondSweepIsNotQueuedBehindAnUnfinishedOne(): void
    {
        $channel = new QueueChannel([0]);
        $coordinator = new WriteCoordinator($channel, new Logger('test', [new TestHandler()]));

        async(static function () use ($coordinator): void {
            $coordinator->purgeExpired();
            $coordinator->purgeExpired();
            delay(0);
        })->await();

        self::assertCount(1, $channel->sent);
    }

    public function testASweepThatRemovedNothingSaysNothing(): void
    {
        $channel = new QueueChannel([0]);
        $handler = new TestHandler();
        $coordinator = new WriteCoordinator($channel, new Logger('test', [$handler]));

        async(static function () use ($coordinator): void {
            $coordinator->purgeExpired();
            delay(0);
        })->await();

        self::assertCount(1, $channel->sent);
        self::assertFalse($handler->hasInfoRecords());
    }

    public function testEventsArePrioritisedOverBanChunks(): void
    {
        $event = SignedEventFactory::signedEvent($this->keyPair, EventKind::fromInt(EventKind::TEXT_NOTE), 'live');
        $channel = new QueueChannel([[EventStoreOutcome::Stored], 0]);
        $coordinator = new WriteCoordinator($channel);

        async(static function () use ($coordinator, $event): void {
            $storeFuture = async(static fn () => $coordinator->store($event));
            $coordinator->purgeByPubkey(SignedEventFactory::pubkey('aa'));
            $storeFuture->await();
        })->await();

        self::assertInstanceOf(StoreEventsCommand::class, $channel->sent[0]);
        self::assertInstanceOf(DeletePubkeyChunkCommand::class, $channel->sent[1]);
    }

    public function testBanChunkIsForcedAfterMaxBatchesOfEvents(): void
    {
        $event = SignedEventFactory::signedEvent($this->keyPair, EventKind::fromInt(EventKind::TEXT_NOTE), 'flood');
        $channel = new QueueChannel([
            array_fill(0, WriteCoordinator::MAX_BATCH, EventStoreOutcome::Stored),
            0,
            [EventStoreOutcome::Stored],
        ]);
        $coordinator = new WriteCoordinator($channel, maxBatchesBeforeDelete: 1);

        async(static function () use ($coordinator, $event): void {
            $futures = [];
            for ($i = 0; $i <= WriteCoordinator::MAX_BATCH; ++$i) {
                $futures[] = async(static fn () => $coordinator->store($event));
            }
            $coordinator->purgeByPubkey(SignedEventFactory::pubkey('aa'));
            foreach ($futures as $future) {
                $future->await();
            }
        })->await();

        self::assertCount(3, $channel->sent);

        $firstBatch = $channel->sent[0];
        self::assertInstanceOf(StoreEventsCommand::class, $firstBatch);
        self::assertCount(WriteCoordinator::MAX_BATCH, $firstBatch);

        self::assertInstanceOf(DeletePubkeyChunkCommand::class, $channel->sent[1]);
        self::assertInstanceOf(StoreEventsCommand::class, $channel->sent[2]);
    }
}
