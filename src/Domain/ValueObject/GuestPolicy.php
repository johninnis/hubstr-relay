<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Domain\ValueObject;

use Innis\Nostr\Core\Domain\Collection\PublicKeyCollection;
use Innis\Nostr\Core\Domain\Collection\TagCollection;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventKind;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use Innis\Nostr\Core\Domain\ValueObject\Tag\Tag;

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
    public static function tryFromArray(array $data): ?self
    {
        $write = GuestWritePolicy::tryFromArray((array) ($data['write'] ?? []));

        return null === $write ? null : new self(GuestReadPolicy::fromArray((array) ($data['read'] ?? [])), $write);
    }

    public function getRead(): GuestReadPolicy
    {
        return $this->read;
    }

    public function getWrite(): GuestWritePolicy
    {
        return $this->write;
    }

    // Deliberate: an inbox admits a wrap addressed to a tenant by whichever provenance route the write policy offers; the read kinds are not asked, because innis/nostr-relay serves a gift wrap only to the key it names whatever they hold — see ADR-0042
    public function servesAsDirectMessageInbox(PublicKeyCollection $tenants): bool
    {
        return array_any(
            $tenants->toArray(),
            fn (PublicKey $tenant): bool => null === $this->write->admissionFailure(EventKind::fromInt(EventKind::GIFT_WRAP), new TagCollection([Tag::pubkey($tenant)]), $tenants),
        );
    }

    /**
     * @return array{read: array{kinds: list<int>, global_kinds: list<int>, from_tenants_only: bool}, write: array{kinds: list<int>, tagged_to_tenant: bool, tag_prefixes: list<array{tag: string, prefixes: list<string>, kinds: list<int>}>}}
     */
    public function toArray(): array
    {
        return [
            'read' => $this->read->toArray(),
            'write' => $this->write->toArray(),
        ];
    }
}
