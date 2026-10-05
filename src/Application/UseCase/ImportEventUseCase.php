<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Application\UseCase;

use Innis\Hubstr\Relay\Application\Port\EventWriterInterface;
use Innis\Hubstr\Relay\Application\Service\ImportAdmission;
use Innis\Hubstr\Relay\Domain\Enum\ImportOutcome;
use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Relay\Domain\Enum\EventStoreOutcome;

final readonly class ImportEventUseCase
{
    public function __construct(
        private ImportAdmission $admission,
        private EventWriterInterface $eventStore,
    ) {
    }

    public function import(string $jsonLine): ImportOutcome
    {
        $event = Event::tryFromJson($jsonLine);

        if (null === $event) {
            return ImportOutcome::Failed;
        }

        $refusal = $this->admission->refuse($event);

        if (null !== $refusal) {
            return $refusal;
        }

        return EventStoreOutcome::Stored === $this->eventStore->store($event)
            ? ImportOutcome::Imported
            : ImportOutcome::Skipped;
    }
}
