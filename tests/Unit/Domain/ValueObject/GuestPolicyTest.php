<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Tests\Unit\Domain\ValueObject;

use Innis\Hubstr\Relay\Domain\Collection\TagPrefixRequirementCollection;
use Innis\Hubstr\Relay\Domain\ValueObject\GuestPolicy;
use Innis\Hubstr\Relay\Domain\ValueObject\GuestReadPolicy;
use Innis\Hubstr\Relay\Domain\ValueObject\GuestWritePolicy;
use Innis\Hubstr\Relay\Domain\ValueObject\TagPrefixRequirement;
use Innis\Nostr\Core\Domain\Collection\EventKindCollection;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventKind;
use Innis\Nostr\Core\Domain\ValueObject\Tag\TagType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class GuestPolicyTest extends TestCase
{
    public function testTheDefaultPolicyServesAsADirectMessageInbox(): void
    {
        $this->assertTrue(GuestPolicy::defaults()->servesAsDirectMessageInbox());
    }

    #[DataProvider('policiesThatAreNotAnInbox')]
    public function testAPolicyThatExposesOrRefusesGiftWrapsIsNotAnInbox(GuestPolicy $policy): void
    {
        $this->assertFalse($policy->servesAsDirectMessageInbox());
    }

    /**
     * @return iterable<string, array{GuestPolicy}>
     */
    public static function policiesThatAreNotAnInbox(): iterable
    {
        yield 'gift wraps guest-readable' => [GuestPolicy::fromArray(['read' => ['kinds' => [EventKind::GIFT_WRAP]]])];
        yield 'gift wraps global' => [GuestPolicy::fromArray(['read' => ['global_kinds' => [EventKind::GIFT_WRAP]]])];
        yield 'gift wraps not writable' => [GuestPolicy::fromArray(['write' => ['kinds' => [EventKind::TEXT_NOTE]]])];
        yield 'wraps need not name a tenant' => [GuestPolicy::fromArray(['write' => ['tagged_to_tenant' => false]])];
    }

    public function testDefaultsHaveExpectedKinds(): void
    {
        $policy = GuestPolicy::defaults();

        $this->assertSame(
            [
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
            ],
            $policy->getRead()->getKinds()->toInts(),
        );
        $this->assertSame(
            [
                EventKind::TEXT_NOTE,
                EventKind::REACTION,
                EventKind::COMMENT,
                EventKind::NUTZAP,
                EventKind::ZAP_RECEIPT,
                EventKind::GIFT_WRAP,
                EventKind::NOSTR_CONNECT,
            ],
            $policy->getWrite()->getKinds()->toInts(),
        );
        $this->assertSame([EventKind::NOSTR_CONNECT], $policy->getRead()->getGlobalKinds()->toInts());
        $this->assertTrue($policy->getRead()->isFromTenantsOnly());
        $this->assertTrue($policy->getWrite()->isTaggedToTenant());
    }

    public function testGlobalKindsAreReadableAndBypassTenantScope(): void
    {
        $read = GuestPolicy::defaults()->getRead();

        $this->assertTrue($read->getKinds()->contains(EventKind::fromInt(EventKind::NOSTR_CONNECT)));
        $this->assertTrue($read->getGlobalKinds()->contains(EventKind::fromInt(EventKind::NOSTR_CONNECT)));
        $this->assertFalse($read->getGlobalKinds()->contains(EventKind::fromInt(EventKind::TEXT_NOTE)));
    }

    public function testGiftWrapsAreGuestWritableButNeverGuestReadable(): void
    {
        $policy = GuestPolicy::defaults();
        $giftWrap = EventKind::fromInt(EventKind::GIFT_WRAP);

        $this->assertTrue($policy->getWrite()->getKinds()->contains($giftWrap));
        $this->assertFalse(
            $policy->getRead()->getKinds()->contains($giftWrap),
            'The kind gate must hide gift wraps independently of the author gate, so that flipping '
            .'from_tenants_only off cannot expose stored direct messages (ADR-0017).',
        );
    }

    public function testGiftWrapsAreNeverAGlobalKind(): void
    {
        $this->assertFalse(
            GuestPolicy::defaults()->getRead()->getGlobalKinds()->contains(EventKind::fromInt(EventKind::GIFT_WRAP)),
            'A global kind bypasses the author gate as well as counting as readable, so kind 1059 there '
            .'would defeat both gift-wrap gates at once (ADR-0017).',
        );
    }

    public function testDmRelayListIsReadableButTenantScoped(): void
    {
        $read = GuestPolicy::defaults()->getRead();

        $this->assertTrue($read->getKinds()->contains(EventKind::fromInt(EventKind::DM_RELAY_LIST)));
        $this->assertFalse($read->getGlobalKinds()->contains(EventKind::fromInt(EventKind::DM_RELAY_LIST)));
    }

    public function testFromArrayReadsGlobalKinds(): void
    {
        $policy = GuestPolicy::fromArray([
            'read' => ['kinds' => [1], 'global_kinds' => [24133], 'from_tenants_only' => true],
            'write' => ['kinds' => [1], 'tagged_to_tenant' => true],
        ]);

        $this->assertSame([EventKind::NOSTR_CONNECT], $policy->getRead()->getGlobalKinds()->toInts());
        $this->assertTrue($policy->getRead()->getGlobalKinds()->contains(EventKind::fromInt(EventKind::NOSTR_CONNECT)));
    }

    public function testIsKindReadable(): void
    {
        $read = GuestPolicy::defaults()->getRead();

        $this->assertTrue($read->getKinds()->contains(EventKind::fromInt(EventKind::TEXT_NOTE)));
        $this->assertFalse($read->getKinds()->contains(EventKind::fromInt(EventKind::ENCRYPTED_DIRECT_MESSAGE)));
    }

    public function testIsKindWritable(): void
    {
        $write = GuestPolicy::defaults()->getWrite();

        $this->assertTrue($write->getKinds()->contains(EventKind::fromInt(EventKind::REACTION)));
        $this->assertFalse($write->getKinds()->contains(EventKind::fromInt(EventKind::METADATA)));
    }

    public function testFromArrayReadsIntegerKinds(): void
    {
        $policy = GuestPolicy::fromArray([
            'read' => ['kinds' => [1, 7], 'from_tenants_only' => false],
            'write' => ['kinds' => [7], 'tagged_to_tenant' => false],
        ]);

        $this->assertSame([1, 7], $policy->getRead()->getKinds()->toInts());
        $this->assertSame([7], $policy->getWrite()->getKinds()->toInts());
    }

    public function testFromArrayDropsNonIntegerKinds(): void
    {
        $policy = GuestPolicy::fromArray([
            'read' => ['kinds' => ['junk', 1, '9', 7], 'from_tenants_only' => true],
            'write' => ['kinds' => [null, [], 7], 'tagged_to_tenant' => true],
        ]);

        $this->assertSame([1, 7], $policy->getRead()->getKinds()->toInts());
        $this->assertSame([7], $policy->getWrite()->getKinds()->toInts());
        $this->assertFalse($policy->getRead()->getKinds()->contains(EventKind::fromInt(EventKind::METADATA)));
        $this->assertFalse($policy->getWrite()->getKinds()->contains(EventKind::fromInt(EventKind::METADATA)));
    }

    public function testRoundTripsViaArray(): void
    {
        $original = new GuestPolicy(
            new GuestReadPolicy(EventKindCollection::fromInts([EventKind::TEXT_NOTE, EventKind::REACTION]), EventKindCollection::fromInts([EventKind::NOSTR_CONNECT]), false),
            new GuestWritePolicy(EventKindCollection::fromInts([EventKind::REACTION, EventKind::ZAP_RECEIPT]), true),
        );
        $restored = GuestPolicy::fromArray($original->toArray());

        $this->assertEquals($original->getRead()->getKinds(), $restored->getRead()->getKinds());
        $this->assertEquals($original->getRead()->getGlobalKinds(), $restored->getRead()->getGlobalKinds());
        $this->assertSame($original->getRead()->isFromTenantsOnly(), $restored->getRead()->isFromTenantsOnly());
        $this->assertEquals($original->getWrite()->getKinds(), $restored->getWrite()->getKinds());
        $this->assertSame($original->getWrite()->isTaggedToTenant(), $restored->getWrite()->isTaggedToTenant());
    }

    public function testDefaultsRequireNoTagPrefix(): void
    {
        $this->assertTrue(GuestPolicy::defaults()->getWrite()->getTagPrefixRequirements()->isEmpty());
    }

    public function testTagPrefixRequirementRoundTripsViaArray(): void
    {
        $original = new GuestPolicy(
            GuestReadPolicy::defaults(),
            new GuestWritePolicy(
                EventKindCollection::fromInts([EventKind::COMMENT]),
                false,
                new TagPrefixRequirementCollection([new TagPrefixRequirement(TagType::rootExternalContent(), ['https://www.example.com/'])]),
            ),
        );

        $restored = GuestPolicy::fromArray($original->toArray());

        $this->assertEquals(
            $original->getWrite()->getTagPrefixRequirements(),
            $restored->getWrite()->getTagPrefixRequirements(),
        );
    }

    public function testAnUnparseableTagPrefixRuleLeavesNoRequirement(): void
    {
        $policy = GuestPolicy::fromArray([
            'read' => ['kinds' => [1], 'from_tenants_only' => true],
            'write' => ['kinds' => [1], 'tagged_to_tenant' => false, 'tag_prefixes' => ['tag' => 'I', 'prefixes' => []]],
        ]);

        $this->assertTrue(
            $policy->getWrite()->getTagPrefixRequirements()->isEmpty(),
            'fromArray stays total; refusing the submission is the RPC boundary\'s job (TenancyRpcHandler).',
        );
    }
}
