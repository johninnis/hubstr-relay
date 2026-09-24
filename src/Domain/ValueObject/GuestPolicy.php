<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Domain\ValueObject;

use Innis\Nostr\Core\Domain\ValueObject\Content\EventKind;

final readonly class GuestPolicy
{
    public function __construct(
        private GuestReadPolicy $read,
        private GuestWritePolicy $write,
    ) {
    }

    public static function defaults(): self
    {
        return new self(GuestReadPolicy::defaults(), GuestWritePolicy::defaults());
    }

    /**
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            GuestReadPolicy::fromArray((array) ($data['read'] ?? [])),
            GuestWritePolicy::fromArray((array) ($data['write'] ?? [])),
        );
    }

    public function getRead(): GuestReadPolicy
    {
        return $this->read;
    }

    public function getWrite(): GuestWritePolicy
    {
        return $this->write;
    }

    // Deliberate: the inbox is a property of the live policy, so whether NIP-17 is advertised follows it — see ADR-0033
    public function servesAsDirectMessageInbox(): bool
    {
        $giftWrap = EventKind::fromInt(EventKind::GIFT_WRAP);

        return $this->write->getKinds()->contains($giftWrap)
            && $this->write->isTaggedToTenant()
            && !$this->read->getKinds()->contains($giftWrap)
            && !$this->read->getGlobalKinds()->contains($giftWrap);
    }

    /**
     * @return array{read: array{kinds: list<int>, global_kinds: list<int>, from_tenants_only: bool}, write: array{kinds: list<int>, tagged_to_tenant: bool, tag_prefixes: list<array{tag: string, prefixes: list<string>}>}}
     */
    public function toArray(): array
    {
        return [
            'read' => $this->read->toArray(),
            'write' => $this->write->toArray(),
        ];
    }
}
