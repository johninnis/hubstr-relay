<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Infrastructure\Persistence;

use Innis\Hubstr\Relay\Domain\Enum\RelayMarker;
use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\Service\TagReferenceExtractor;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventKind;
use Innis\Nostr\Core\Domain\ValueObject\Payment\ZapReceipt;

final readonly class Denormaliser
{
    public function __construct(
        private StatementRunner $statements,
    ) {
    }

    // Deliberate: no counters are maintained here — derived stats recompute from the base tables — see ADR-0012
    public function denormalise(Event $event): void
    {
        $kind = $event->getKind();

        match (true) {
            $kind->is(EventKind::FOLLOW_LIST) => $this->denormaliseProfileList($event, ProfileList::Follows),
            $kind->is(EventKind::MUTE_LIST) => $this->denormaliseProfileList($event, ProfileList::Mutes),
            $kind->is(EventKind::RELAY_LIST) => $this->denormaliseRelays($event),
            $kind->is(EventKind::ZAP_RECEIPT) => $this->denormaliseZap($event),
            default => null,
        };
    }

    private function denormaliseProfileList(Event $event, ProfileList $list): void
    {
        $pubkeyBin = $event->getPubkey()->toBytes();

        $this->statements->execute("DELETE FROM {$list->table()} WHERE {$list->ownerColumn()} = ?", [$pubkeyBin]);

        $insert = "INSERT OR IGNORE INTO {$list->table()} ({$list->ownerColumn()}, {$list->targetColumn()}) VALUES (?, ?)";

        foreach ($event->getTags()->getPubkeys() as $target) {
            $this->statements->execute($insert, [$pubkeyBin, $target->toBytes()]);
        }
    }

    private function denormaliseRelays(Event $event): void
    {
        $pubkeyBin = $event->getPubkey()->toBytes();

        $this->statements->execute('DELETE FROM profile_relays WHERE pubkey = ?', [$pubkeyBin]);

        foreach (TagReferenceExtractor::extract($event->getTags())->getRelays() as $relay) {
            $this->statements->execute(
                'INSERT OR IGNORE INTO profile_relays (pubkey, relay_url, marker) VALUES (?, ?, ?)',
                [$pubkeyBin, (string) $relay->getRelayUrl(), RelayMarker::fromMode($relay->getMode())->value],
            );
        }
    }

    private function denormaliseZap(Event $event): void
    {
        $receipt = ZapReceipt::tryFromEvent($event);
        $recipientPubkey = $receipt?->getRecipientPubkey();
        if (null === $receipt || null === $recipientPubkey) {
            return;
        }

        $this->statements->execute(
            'INSERT OR IGNORE INTO zap_receipts (event_id, sender_pubkey, recipient_pubkey, amount_msats) VALUES (?, ?, ?, ?)',
            [
                $event->getId()->toBytes(),
                $receipt->getSenderPubkey()?->toBytes(),
                $recipientPubkey->toBytes(),
                $receipt->getAmount()->toMillisats(),
            ],
        );
    }
}
