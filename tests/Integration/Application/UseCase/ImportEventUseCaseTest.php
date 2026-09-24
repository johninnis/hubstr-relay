<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Tests\Integration\Application\UseCase;

use Innis\Hubstr\Relay\Application\Port\EventWriterInterface;
use Innis\Hubstr\Relay\Application\Port\PolicyStateInterface;
use Innis\Hubstr\Relay\Application\UseCase\ImportEventUseCase;
use Innis\Hubstr\Relay\Domain\Enum\ImportOutcome;
use Innis\Hubstr\Relay\Tests\Support\SignedEventFactory;
use Innis\Nostr\Core\Domain\Service\EventValidatorInterface;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventKind;
use Innis\Nostr\Core\Domain\ValueObject\Identity\KeyPair;
use Innis\Nostr\Relay\Domain\Enum\EventStoreOutcome;
use PHPUnit\Framework\TestCase;

final class ImportEventUseCaseTest extends TestCase
{
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

    private function useCase(bool $valid, bool $blacklisted, EventStoreOutcome $storeOutcome): ImportEventUseCase
    {
        $validator = $this->createStub(EventValidatorInterface::class);
        $validator->method('isEventValid')->willReturn($valid);

        $policyState = $this->createStub(PolicyStateInterface::class);
        $policyState->method('isEventBlacklisted')->willReturn($blacklisted);

        $eventStore = $this->createStub(EventWriterInterface::class);
        $eventStore->method('store')->willReturn($storeOutcome);

        return new ImportEventUseCase($validator, $policyState, $eventStore);
    }

    private function eventLine(): string
    {
        $event = SignedEventFactory::signedEvent($this->keyPair, EventKind::fromInt(EventKind::TEXT_NOTE), 'imported note');

        return (string) json_encode($event->toArray(), JSON_THROW_ON_ERROR);
    }
}
