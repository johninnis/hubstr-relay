<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Tests\Integration\Application\UseCase;

use Innis\Hubstr\Core\Infrastructure\Persistence\SchemaMigrator;
use Innis\Hubstr\Core\Infrastructure\Persistence\SqliteDatabase;
use Innis\Hubstr\Relay\Application\Port\EventWriterInterface;
use Innis\Hubstr\Relay\Application\Port\PolicyStateInterface;
use Innis\Hubstr\Relay\Application\Service\ImportAdmission;
use Innis\Hubstr\Relay\Application\UseCase\ImportEventUseCase;
use Innis\Hubstr\Relay\Domain\Enum\ImportOutcome;
use Innis\Hubstr\Relay\Infrastructure\Persistence\EventQueryStore;
use Innis\Hubstr\Relay\Infrastructure\Worker\WriteContext;
use Innis\Hubstr\Relay\Tests\Support\SignedEventFactory;
use Innis\Nostr\Core\Application\Port\ClockInterface;
use Innis\Nostr\Core\Domain\Collection\EventIdCollection;
use Innis\Nostr\Core\Domain\Collection\FilterCollection;
use Innis\Nostr\Core\Domain\Service\EventValidatorInterface;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventKind;
use Innis\Nostr\Core\Domain\ValueObject\Identity\KeyPair;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Filter;
use Innis\Nostr\Core\Domain\ValueObject\Timestamp;
use Innis\Nostr\Relay\Domain\Enum\EventStoreOutcome;
use Innis\Nostr\Relay\Domain\ValueObject\StoredEvent;
use PHPUnit\Framework\TestCase;

final class ImportEventUseCaseTest extends TestCase
{
    private const int NOW = 1_700_000_000;

    private KeyPair $keyPair;

    protected function setUp(): void
    {
        $this->keyPair = KeyPair::generate(SignedEventFactory::signer());
    }

    public function testImportsAValidUnblacklistedEvent(): void
    {
        $outcome = $this->useCase(valid: true, blacklisted: false, storeOutcome: EventStoreOutcome::Stored)
            ->import($this->eventLine());

        self::assertSame(ImportOutcome::Imported, $outcome);
    }

    public function testSkipsABlacklistedEvent(): void
    {
        $outcome = $this->useCase(valid: true, blacklisted: true, storeOutcome: EventStoreOutcome::Stored)
            ->import($this->eventLine());

        self::assertSame(ImportOutcome::Skipped, $outcome);
    }

    public function testSkipsAnEphemeralEventWithoutStoringIt(): void
    {
        $eventStore = $this->createMock(EventWriterInterface::class);
        $eventStore->expects($this->never())->method('store');
        $event = SignedEventFactory::signedEvent($this->keyPair, EventKind::fromInt(EventKind::EPHEMERAL_GIFT_WRAP), 'ciphertext');

        $outcome = $this->useCase(valid: true, blacklisted: false, storeOutcome: EventStoreOutcome::Stored, eventStore: $eventStore)
            ->import($event->toJson());

        self::assertSame(ImportOutcome::Skipped, $outcome);
    }

    public function testSkipsAnEventTheStoreReportsAsDuplicate(): void
    {
        $outcome = $this->useCase(valid: true, blacklisted: false, storeOutcome: EventStoreOutcome::Duplicate)
            ->import($this->eventLine());

        self::assertSame(ImportOutcome::Skipped, $outcome);
    }

    public function testFailsAnEventThatDoesNotValidate(): void
    {
        $outcome = $this->useCase(valid: false, blacklisted: false, storeOutcome: EventStoreOutcome::Stored)
            ->import($this->eventLine());

        self::assertSame(ImportOutcome::Failed, $outcome);
    }

    public function testFailsALineThatIsNotJson(): void
    {
        $outcome = $this->useCase(valid: true, blacklisted: false, storeOutcome: EventStoreOutcome::Stored)
            ->import('not json at all');

        self::assertSame(ImportOutcome::Failed, $outcome);
    }

    public function testFailsJsonThatIsNotAnObject(): void
    {
        $outcome = $this->useCase(valid: true, blacklisted: false, storeOutcome: EventStoreOutcome::Stored)
            ->import('42');

        self::assertSame(ImportOutcome::Failed, $outcome);
    }

    public function testFailsAnIncompleteEventObject(): void
    {
        $outcome = $this->useCase(valid: true, blacklisted: false, storeOutcome: EventStoreOutcome::Stored)
            ->import('{"kind":1}');

        self::assertSame(ImportOutcome::Failed, $outcome);
    }

    public function testJudgesValidityAgainstTheInjectedClock(): void
    {
        $validator = $this->createMock(EventValidatorInterface::class);
        $validator->expects($this->once())->method('isEventValid')->with(
            $this->anything(),
            $this->callback(static fn (Timestamp $reference): bool => self::NOW === $reference->toInt()),
        )->willReturn(true);

        $this->useCase(valid: true, blacklisted: false, storeOutcome: EventStoreOutcome::Stored, validator: $validator)
            ->import($this->eventLine());
    }

    public function testAnImportedLineIsStoredAsTheEventsOwnEncodingNotAsTheLineWasWritten(): void
    {
        $event = SignedEventFactory::signedEvent($this->keyPair, EventKind::fromInt(EventKind::TEXT_NOTE), 'imported note');
        $line = json_encode([...$event->toArray(), 'evil' => 'unsigned'], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        $pdo = SqliteDatabase::inMemory()->connect();
        new SchemaMigrator($pdo)->migrate(dirname(__DIR__, 4).'/resources/migrations');
        $validator = $this->createStub(EventValidatorInterface::class);
        $validator->method('isEventValid')->willReturn(true);
        $policyState = $this->createStub(PolicyStateInterface::class);
        $clock = $this->createStub(ClockInterface::class);
        $clock->method('now')->willReturn(Timestamp::fromInt(self::NOW));

        new ImportEventUseCase(new ImportAdmission($validator, $policyState, $clock), WriteContext::forConnection($pdo)->getEventWriteStore())->import($line);

        $stored = new EventQueryStore($pdo)->findByFilters(new FilterCollection([Filter::from(ids: new EventIdCollection([$event->getId()]))]))->toArray();
        self::assertSame([$event->toJson()], array_map(static fn (StoredEvent $row): string => $row->getEncoded()->toJson(), $stored));
    }

    private function useCase(bool $valid, bool $blacklisted, EventStoreOutcome $storeOutcome, ?EventValidatorInterface $validator = null, ?EventWriterInterface $eventStore = null): ImportEventUseCase
    {
        if (null === $validator) {
            $validator = $this->createStub(EventValidatorInterface::class);
            $validator->method('isEventValid')->willReturn($valid);
        }

        $policyState = $this->createStub(PolicyStateInterface::class);
        $policyState->method('isEventBlacklisted')->willReturn($blacklisted);

        if (null === $eventStore) {
            $eventStore = $this->createStub(EventWriterInterface::class);
            $eventStore->method('store')->willReturn($storeOutcome);
        }

        $clock = $this->createStub(ClockInterface::class);
        $clock->method('now')->willReturn(Timestamp::fromInt(self::NOW));

        return new ImportEventUseCase(new ImportAdmission($validator, $policyState, $clock), $eventStore);
    }

    private function eventLine(): string
    {
        $event = SignedEventFactory::signedEvent($this->keyPair, EventKind::fromInt(EventKind::TEXT_NOTE), 'imported note');

        return (string) json_encode($event->toArray(), JSON_THROW_ON_ERROR);
    }
}
