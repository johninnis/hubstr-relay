<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Domain\ValueObject;

use Innis\Nostr\Core\Domain\Collection\EventKindCollection;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventKind;

final readonly class GuestReadPolicy
{
    // Deliberate: kind 1059 is absent here and from the global kinds below — gift wraps are guest-writable, never guest-readable. See ADR-0017.
    private const array DEFAULT_KINDS = [
        EventKind::METADATA,
        EventKind::TEXT_NOTE,
        EventKind::FOLLOW_LIST,
        EventKind::REPOST,
        EventKind::REACTION,
        EventKind::GENERIC_REPOST,
        EventKind::PICTURE,
        EventKind::VIDEO,
        EventKind::SHORT_FORM_VIDEO,
        EventKind::HIGHLIGHT,
        EventKind::COMMENT,
        EventKind::NUTZAP,
        EventKind::ZAP_RECEIPT,
        EventKind::MUTE_LIST,
        EventKind::PIN_LIST,
        EventKind::RELAY_LIST,
        EventKind::DM_RELAY_LIST,
        EventKind::BOOKMARK_LIST,
        EventKind::INTERESTS_LIST,
        EventKind::CUSTOM_EMOJI_LIST,
        EventKind::BLOSSOM_SERVER_LIST,
        EventKind::BOOKMARK_SET,
        EventKind::CURATION_SET_ARTICLES,
        EventKind::LONGFORM_CONTENT,
        EventKind::EMOJI_SET,
        EventKind::NOSTR_CONNECT,
    ];
    // Deliberate: kind 24133 is readable regardless of author, because a NIP-46 request is written by an ephemeral key no tenant gate would pass — see ADR-0013
    private const array DEFAULT_GLOBAL_KINDS = [
        EventKind::NOSTR_CONNECT,
    ];
    private const bool DEFAULT_FROM_TENANTS_ONLY = true;

    public function __construct(
        private EventKindCollection $kinds,
        private EventKindCollection $globalKinds,
        private bool $fromTenantsOnly,
    ) {
    }

    public static function defaults(): self
    {
        return new self(
            EventKindCollection::fromInts(self::DEFAULT_KINDS),
            EventKindCollection::fromInts(self::DEFAULT_GLOBAL_KINDS),
            self::DEFAULT_FROM_TENANTS_ONLY,
        );
    }

    /**
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            EventKindCollection::fromInts($data['kinds'] ?? self::DEFAULT_KINDS),
            EventKindCollection::fromInts($data['global_kinds'] ?? self::DEFAULT_GLOBAL_KINDS),
            (bool) ($data['from_tenants_only'] ?? self::DEFAULT_FROM_TENANTS_ONLY),
        );
    }

    public function getKinds(): EventKindCollection
    {
        return $this->kinds;
    }

    public function getGlobalKinds(): EventKindCollection
    {
        return $this->globalKinds;
    }

    public function isFromTenantsOnly(): bool
    {
        return $this->fromTenantsOnly;
    }

    /**
     * @return array{kinds: list<int>, global_kinds: list<int>, from_tenants_only: bool}
     */
    public function toArray(): array
    {
        return [
            'kinds' => $this->kinds->toInts(),
            'global_kinds' => $this->globalKinds->toInts(),
            'from_tenants_only' => $this->fromTenantsOnly,
        ];
    }
}
