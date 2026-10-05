<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Application\Service;

use Innis\Hubstr\Relay\Application\Port\PolicyStateInterface;
use Innis\Hubstr\Relay\Domain\Enum\ImportOutcome;
use Innis\Nostr\Core\Application\Port\ClockInterface;
use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\Enum\EventKindCategory;
use Innis\Nostr\Core\Domain\Service\EventValidatorInterface;

final readonly class ImportAdmission
{
    public function __construct(
        private EventValidatorInterface $validator,
        private PolicyStateInterface $policyState,
        private ClockInterface $clock,
    ) {
    }

    public function refuse(Event $event): ?ImportOutcome
    {
        if (!$this->validator->isEventValid($event, $this->clock->now())) {
            return ImportOutcome::Failed;
        }

        if (EventKindCategory::Ephemeral === $event->getKind()->category() || $this->policyState->isEventBlacklisted($event)) {
            return ImportOutcome::Skipped;
        }

        return null;
    }
}
