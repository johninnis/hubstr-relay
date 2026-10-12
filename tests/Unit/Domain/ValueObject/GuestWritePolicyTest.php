<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Tests\Unit\Domain\ValueObject;

use Innis\Hubstr\Relay\Domain\Collection\TagPrefixRequirementCollection;
use Innis\Hubstr\Relay\Domain\Failure\GuestWriteFailure;
use Innis\Hubstr\Relay\Domain\ValueObject\GuestWritePolicy;
use Innis\Hubstr\Relay\Domain\ValueObject\TagPrefixRequirement;
use Innis\Nostr\Core\Domain\Collection\EventKindCollection;
use Innis\Nostr\Core\Domain\Collection\PublicKeyCollection;
use Innis\Nostr\Core\Domain\Collection\TagCollection;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventKind;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use Innis\Nostr\Core\Domain\ValueObject\Tag\Tag;
use Innis\Nostr\Core\Domain\ValueObject\Tag\TagType;
use PHPUnit\Framework\TestCase;

final class GuestWritePolicyTest extends TestCase
{
    private const string SITE = 'https://www.example.com/';
    private const string TENANT_HEX = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const string STRANGER_HEX = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    public function testAKindOutsideTheWritableKindsIsRefusedAsNotWritable(): void
    {
        $this->assertSame(
            GuestWriteFailure::KindNotWritable,
            self::sitePolicy(taggedToTenant: true)->admissionFailure(EventKind::fromInt(EventKind::METADATA), self::taggedTo(self::TENANT_HEX), self::tenants()),
        );
    }

    public function testARuleNamingAKindOutsideTheWritableKindsDoesNotMakeItWritable(): void
    {
        $policy = new GuestWritePolicy(
            EventKindCollection::fromInts([EventKind::COMMENT]),
            false,
            new TagPrefixRequirementCollection([self::rule(TagType::rootExternalContent(), [EventKind::COMMENT, EventKind::TEXT_NOTE])]),
        );

        $this->assertSame(
            GuestWriteFailure::KindNotWritable,
            $policy->admissionFailure(EventKind::fromInt(EventKind::TEXT_NOTE), self::pageTagged('I'), self::tenants()),
        );
    }

    public function testAPageRuleAdmitsAnEventOfItsKinds(): void
    {
        $this->assertNull(self::sitePolicy(taggedToTenant: false)->admissionFailure(EventKind::fromInt(EventKind::COMMENT), self::pageTagged('I'), self::tenants()));
    }

    public function testAPageRuleDoesNotAdmitAGiftWrapToAStrangerCarryingThePageTag(): void
    {
        $tags = new TagCollection([...self::taggedTo(self::STRANGER_HEX)->toArray(), ...self::pageTagged('I')->toArray()]);

        $this->assertSame(
            GuestWriteFailure::NoRecognisedTag,
            self::sitePolicy(taggedToTenant: false)->admissionFailure(EventKind::fromInt(EventKind::GIFT_WRAP), $tags, self::tenants()),
        );
    }

    public function testAnEventTaggedToATenantIsAdmittedWhateverKindsTheRulesList(): void
    {
        $this->assertNull(self::sitePolicy(taggedToTenant: true)->admissionFailure(EventKind::fromInt(EventKind::GIFT_WRAP), self::taggedTo(self::TENANT_HEX), self::tenants()));
    }

    public function testAnEventMeetingNeitherConfiguredCheckIsRefusedNamingBoth(): void
    {
        $this->assertSame(
            GuestWriteFailure::NoTenantReferenceNorRecognisedTag,
            self::sitePolicy(taggedToTenant: true)->admissionFailure(EventKind::fromInt(EventKind::GIFT_WRAP), self::taggedTo(self::STRANGER_HEX), self::tenants()),
        );
    }

    public function testAnEventNotTaggedToATenantIsRefusedWhenThatIsTheOnlyCheck(): void
    {
        $policy = new GuestWritePolicy(EventKindCollection::fromInts([EventKind::GIFT_WRAP]), true);

        $this->assertSame(
            GuestWriteFailure::NoTenantReference,
            $policy->admissionFailure(EventKind::fromInt(EventKind::GIFT_WRAP), self::taggedTo(self::STRANGER_HEX), self::tenants()),
        );
    }

    public function testAnyEventOfAWritableKindIsAdmittedWhenNoCheckIsConfigured(): void
    {
        $policy = new GuestWritePolicy(EventKindCollection::fromInts([EventKind::GIFT_WRAP]), false);

        $this->assertNull($policy->admissionFailure(EventKind::fromInt(EventKind::GIFT_WRAP), new TagCollection(), self::tenants()));
    }

    private static function sitePolicy(bool $taggedToTenant): GuestWritePolicy
    {
        return new GuestWritePolicy(
            EventKindCollection::fromInts([EventKind::COMMENT, EventKind::TEXT_NOTE, EventKind::WEBSITE_REACTION, EventKind::GIFT_WRAP]),
            $taggedToTenant,
            new TagPrefixRequirementCollection([
                self::rule(TagType::rootExternalContent(), [EventKind::COMMENT]),
                self::rule(TagType::externalContent(), [EventKind::TEXT_NOTE, EventKind::WEBSITE_REACTION]),
            ]),
        );
    }

    /**
     * @param list<int> $kinds
     */
    private static function rule(TagType $tag, array $kinds): TagPrefixRequirement
    {
        return new TagPrefixRequirement($tag, [self::SITE], EventKindCollection::fromInts($kinds));
    }

    private static function pageTagged(string $tag): TagCollection
    {
        return new TagCollection([Tag::fromArray([$tag, self::SITE.'page'])]);
    }

    private static function taggedTo(string $pubkeyHex): TagCollection
    {
        return new TagCollection([Tag::pubkey(self::key($pubkeyHex))]);
    }

    private static function tenants(): PublicKeyCollection
    {
        return new PublicKeyCollection([self::key(self::TENANT_HEX)]);
    }

    private static function key(string $hex): PublicKey
    {
        return PublicKey::tryFromHex($hex) ?? self::fail('the test key did not parse');
    }
}
