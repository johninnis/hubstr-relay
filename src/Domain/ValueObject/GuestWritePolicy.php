<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Domain\ValueObject;

use Innis\Hubstr\Relay\Domain\Collection\TagPrefixRequirementCollection;
use Innis\Hubstr\Relay\Domain\Failure\GuestWriteFailure;
use Innis\Nostr\Core\Domain\Collection\EventKindCollection;
use Innis\Nostr\Core\Domain\Collection\PublicKeyCollection;
use Innis\Nostr\Core\Domain\Collection\TagCollection;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventKind;

final readonly class GuestWritePolicy
{
    // Deliberate: both gift-wrap kinds are guest-writable so any sender can deliver to a tenant, and neither is guest-readable — see ADR-0036
    private const array DEFAULT_KINDS = [
        EventKind::TEXT_NOTE,
        EventKind::REACTION,
        EventKind::COMMENT,
        EventKind::NUTZAP,
        EventKind::ZAP_RECEIPT,
        EventKind::GIFT_WRAP,
        EventKind::EPHEMERAL_GIFT_WRAP,
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

    // Deliberate: lenient with a kind list, where a dropped entry only narrows, and strict with the tag prefix rules, where a dropped rule widens — see ADR-0044
    /**
     * @param array<array-key, mixed> $data
     */
    public static function tryFromArray(array $data): ?self
    {
        $tagPrefixRequirements = TagPrefixRequirementCollection::tryFromArray($data['tag_prefixes'] ?? []);

        if (null === $tagPrefixRequirements) {
            return null;
        }

        return new self(
            EventKindCollection::fromInts($data['kinds'] ?? self::DEFAULT_KINDS),
            (bool) ($data['tagged_to_tenant'] ?? self::DEFAULT_TAGGED_TO_TENANT),
            $tagPrefixRequirements,
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

    // Deliberate: the kind check is a precondition, and the configured provenance checks are alternatives, never conjuncts — see ADR-0019
    public function admissionFailure(EventKind $kind, TagCollection $tags, PublicKeyCollection $tenants): ?GuestWriteFailure
    {
        if (!$this->kinds->contains($kind)) {
            return GuestWriteFailure::KindNotWritable;
        }

        $byTenantTag = $this->taggedToTenant;
        $byRule = !$this->tagPrefixRequirements->isEmpty();

        if ($byTenantTag && !$tags->getPubkeys()->intersect($tenants)->isEmpty()) {
            return null;
        }

        if ($byRule && $this->tagPrefixRequirements->isSatisfiedBy($kind, $tags)) {
            return null;
        }

        return match (true) {
            $byTenantTag && $byRule => GuestWriteFailure::NoTenantReferenceNorRecognisedTag,
            $byTenantTag => GuestWriteFailure::NoTenantReference,
            $byRule => GuestWriteFailure::NoRecognisedTag,
            default => null,
        };
    }

    /**
     * @return array{kinds: list<int>, tagged_to_tenant: bool, tag_prefixes: list<array{tag: string, prefixes: list<string>, kinds: list<int>}>}
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
