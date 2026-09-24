<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Application\UseCase;

use Innis\Hubstr\Relay\Application\Port\EventWriterInterface;
use Innis\Hubstr\Relay\Application\Port\PolicyStateInterface;
use Innis\Hubstr\Relay\Domain\Enum\ImportOutcome;
use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\Service\EventValidatorInterface;
use Innis\Nostr\Relay\Domain\Enum\EventStoreOutcome;

final readonly class ImportEventUseCase
{
    public function __construct(
        private EventValidatorInterface $validator,
        private PolicyStateInterface $policyState,
        private EventWriterInterface $eventStore,
    ) {
    }

    public function import(string $jsonLine): ImportOutcome
    {
        $event = Event::tryFromJson($jsonLine);

        if (null === $event) {
            return ImportOutcome::Failed;
        }

        if (!$this->validator->isEventValid($event)) {
            return ImportOutcome::Failed;
        }

        if ($this->policyState->isEventBlacklisted($event)) {
            return ImportOutcome::Skipped;
        }

        return EventStoreOutcome::Stored === $this->eventStore->store($event)
            ? ImportOutcome::Imported
            : ImportOutcome::Skipped;
    }
}
