<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Domain\ValueObject;

use Innis\Hubstr\Relay\Domain\Collection\TagPrefixRequirementCollection;
use Innis\Nostr\Core\Domain\Collection\EventKindCollection;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventKind;

final readonly class GuestWritePolicy
{
    private const array DEFAULT_KINDS = [
        EventKind::TEXT_NOTE,
        EventKind::REACTION,
        EventKind::COMMENT,
        EventKind::NUTZAP,
        EventKind::ZAP_RECEIPT,
        EventKind::GIFT_WRAP,
        EventKind::NOSTR_CONNECT,
    ];
    private const bool DEFAULT_TAGGED_TO_TENANT = true;

    public function __construct(
        private EventKindCollection $kinds,
        private bool $taggedToTenant,
        private TagPrefixRequirementCollection $tagPrefixRequirements = new TagPrefixRequirementCollection(),
    ) {
    }

    public static function defaults(): self
    {
        return new self(EventKindCollection::fromInts(self::DEFAULT_KINDS), self::DEFAULT_TAGGED_TO_TENANT);
    }

    /**
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            EventKindCollection::fromInts($data['kinds'] ?? self::DEFAULT_KINDS),
            (bool) ($data['tagged_to_tenant'] ?? self::DEFAULT_TAGGED_TO_TENANT),
            TagPrefixRequirementCollection::tryFromArray((array) ($data['tag_prefixes'] ?? [])) ?? new TagPrefixRequirementCollection(),
        );
    }

    public function getKinds(): EventKindCollection
    {
        return $this->kinds;
    }

    public function isTaggedToTenant(): bool
    {
        return $this->taggedToTenant;
    }

    public function getTagPrefixRequirements(): TagPrefixRequirementCollection
    {
        return $this->tagPrefixRequirements;
    }

    /**
     * @return array{kinds: list<int>, tagged_to_tenant: bool, tag_prefixes: list<array{tag: string, prefixes: list<string>}>}
     */
    public function toArray(): array
    {
        return [
            'kinds' => $this->kinds->toInts(),
            'tagged_to_tenant' => $this->taggedToTenant,
            'tag_prefixes' => $this->tagPrefixRequirements->toWireArray(),
        ];
    }
}
