<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Tests\Unit\Domain\ValueObject;

use Innis\Hubstr\Relay\Domain\Collection\TagPrefixRequirementCollection;
use Innis\Hubstr\Relay\Domain\ValueObject\GuestPolicy;
use Innis\Hubstr\Relay\Domain\ValueObject\GuestReadPolicy;
use Innis\Hubstr\Relay\Domain\ValueObject\GuestWritePolicy;
use Innis\Hubstr\Relay\Domain\ValueObject\TagPrefixRequirement;
use Innis\Nostr\Core\Domain\Collection\EventKindCollection;
use Innis\Nostr\Core\Domain\Collection\PublicKeyCollection;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventKind;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use Innis\Nostr\Core\Domain\ValueObject\Tag\TagType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class GuestPolicyTest extends TestCase
{
    private const string TENANT_HEX = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    public function testTheDefaultPolicyServesAsADirectMessageInbox(): void
    {
        $this->assertTrue(GuestPolicy::defaults()->servesAsDirectMessageInbox(self::tenants()));
    }

    public function testARelayWithNoTenantIsNotAnInbox(): void
    {
        $this->assertFalse(GuestPolicy::defaults()->servesAsDirectMessageInbox(new PublicKeyCollection()));
    }

    #[DataProvider('policiesThatRefuseAWrapAddressedToATenant')]
    public function testAPolicyThatRefusesAWrapAddressedToATenantIsNotAnInbox(GuestPolicy $policy): void
    {
        $this->assertFalse($policy->servesAsDirectMessageInbox(self::tenants()));
    }

    /**
     * @return iterable<string, array{GuestPolicy}>
     */
    public static function policiesThatRefuseAWrapAddressedToATenant(): iterable
    {
        yield 'gift wraps not writable' => [self::policy(['write' => ['kinds' => [EventKind::TEXT_NOTE]]])];
        yield 'only a page tag admits' => [self::policy(['write' => ['tagged_to_tenant' => false, 'tag_prefixes' => [['tag' => 'I', 'prefixes' => ['https://www.example.com/'], 'kinds' => [EventKind::COMMENT]]]]])];
        yield 'a page tag rule scoped to gift wraps' => [self::policy(['write' => ['tagged_to_tenant' => false, 'tag_prefixes' => [['tag' => 'I', 'prefixes' => ['https://www.example.com/'], 'kinds' => [EventKind::GIFT_WRAP]]]]])];
        yield 'a p tag prefix no tenant matches' => [self::policy(['write' => ['tagged_to_tenant' => false, 'tag_prefixes' => [['tag' => 'p', 'prefixes' => ['bbbb'], 'kinds' => [EventKind::GIFT_WRAP]]]]])];
        yield 'a p tag prefix the tenant matches, scoped to other kinds' => [self::policy(['write' => ['tagged_to_tenant' => false, 'tag_prefixes' => [['tag' => 'p', 'prefixes' => ['aaaa'], 'kinds' => [EventKind::TEXT_NOTE]]]]])];
    }

    #[DataProvider('policiesThatAdmitAWrapAddressedToATenant')]
    public function testAPolicyThatAdmitsAWrapAddressedToATenantServesAsAnInbox(GuestPolicy $policy): void
    {
        $this->assertTrue($policy->servesAsDirectMessageInbox(self::tenants()));
    }

    /**
     * @return iterable<string, array{GuestPolicy}>
     */
    public static function policiesThatAdmitAWrapAddressedToATenant(): iterable
    {
        yield 'gift wraps guest-readable' => [self::policy(['read' => ['kinds' => [EventKind::GIFT_WRAP]]])];
        yield 'gift wraps global' => [self::policy(['read' => ['global_kinds' => [EventKind::GIFT_WRAP]]])];
        yield 'a p tag prefix the tenant matches, scoped to gift wraps' => [self::policy(['write' => ['tagged_to_tenant' => false, 'tag_prefixes' => [['tag' => 'p', 'prefixes' => ['aaaa'], 'kinds' => [EventKind::GIFT_WRAP]]]]])];
        yield 'no provenance check configured' => [self::policy(['write' => ['tagged_to_tenant' => false]])];
        yield 'a page tag beside tagged_to_tenant' => [self::policy(['write' => ['tagged_to_tenant' => true, 'tag_prefixes' => [['tag' => 'I', 'prefixes' => ['https://www.example.com/'], 'kinds' => [EventKind::COMMENT]]]]])];
    }

    private static function tenants(): PublicKeyCollection
    {
        return new PublicKeyCollection([PublicKey::tryFromHex(self::TENANT_HEX) ?? self::fail('the tenant key did not parse')]);
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function policy(array $data): GuestPolicy
    {
        return GuestPolicy::tryFromArray($data) ?? self::fail('the guest policy did not parse');
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
                EventKind::EPHEMERAL_GIFT_WRAP,
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

    #[DataProvider('giftWrapKinds')]
    public function testGiftWrapsAreGuestWritableButNeverGuestReadable(int $kind): void
    {
        $policy = GuestPolicy::defaults();
        $giftWrap = EventKind::fromInt($kind);

        $this->assertTrue($policy->getWrite()->getKinds()->contains($giftWrap));
        $this->assertFalse(
            $policy->getRead()->getKinds()->contains($giftWrap),
            'The kind gate must hide gift wraps independently of the author gate, so that flipping '
            .'from_tenants_only off cannot expose direct messages (ADR-0036).',
        );
    }

    #[DataProvider('giftWrapKinds')]
    public function testGiftWrapsAreNeverAGlobalKind(int $kind): void
    {
        $this->assertFalse(
            GuestPolicy::defaults()->getRead()->getGlobalKinds()->contains(EventKind::fromInt($kind)),
            'A global kind bypasses the author gate as well as counting as readable, so a gift wrap there '
            .'would defeat both gift-wrap gates at once (ADR-0036).',
        );
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function giftWrapKinds(): iterable
    {
        yield 'kind 1059' => [EventKind::GIFT_WRAP];
        yield 'kind 21059' => [EventKind::EPHEMERAL_GIFT_WRAP];
    }

    public function testDmRelayListIsReadableButTenantScoped(): void
    {
        $read = GuestPolicy::defaults()->getRead();

        $this->assertTrue($read->getKinds()->contains(EventKind::fromInt(EventKind::DM_RELAY_LIST)));
        $this->assertFalse($read->getGlobalKinds()->contains(EventKind::fromInt(EventKind::DM_RELAY_LIST)));
    }

    public function testFromArrayReadsGlobalKinds(): void
    {
        $this->assertSame([EventKind::NOSTR_CONNECT], self::policyWithAGlobalKind()->getRead()->getGlobalKinds()->toInts());
    }

    public function testAGlobalKindReadFromAnArrayIsAGlobalKind(): void
    {
        $this->assertTrue(self::policyWithAGlobalKind()->getRead()->getGlobalKinds()->contains(EventKind::fromInt(EventKind::NOSTR_CONNECT)));
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

    public function testFromArrayReadsIntegerReadKinds(): void
    {
        $this->assertSame([1, 7], self::policyWithIntegerKinds()->getRead()->getKinds()->toInts());
    }

    public function testFromArrayReadsIntegerWriteKinds(): void
    {
        $this->assertSame([7], self::policyWithIntegerKinds()->getWrite()->getKinds()->toInts());
    }

    public function testFromArrayDropsNonIntegerReadKinds(): void
    {
        $this->assertSame([1, 7], self::policyWithNonIntegerKinds()->getRead()->getKinds()->toInts());
    }

    public function testFromArrayDropsNonIntegerWriteKinds(): void
    {
        $this->assertSame([7], self::policyWithNonIntegerKinds()->getWrite()->getKinds()->toInts());
    }

    public function testANonIntegerReadKindIsNotReadAsKindZero(): void
    {
        $this->assertFalse(self::policyWithNonIntegerKinds()->getRead()->getKinds()->contains(EventKind::fromInt(EventKind::METADATA)));
    }

    public function testANonIntegerWriteKindIsNotReadAsKindZero(): void
    {
        $this->assertFalse(self::policyWithNonIntegerKinds()->getWrite()->getKinds()->contains(EventKind::fromInt(EventKind::METADATA)));
    }

    public function testRoundTripsViaArray(): void
    {
        $original = new GuestPolicy(
            new GuestReadPolicy(EventKindCollection::fromInts([EventKind::TEXT_NOTE, EventKind::REACTION]), EventKindCollection::fromInts([EventKind::NOSTR_CONNECT]), false),
            new GuestWritePolicy(EventKindCollection::fromInts([EventKind::REACTION, EventKind::ZAP_RECEIPT]), true),
        );

        $this->assertEquals($original, self::policy($original->toArray()));
    }

    private static function policyWithAGlobalKind(): GuestPolicy
    {
        return self::policy([
            'read' => ['kinds' => [1], 'global_kinds' => [24133], 'from_tenants_only' => true],
            'write' => ['kinds' => [1], 'tagged_to_tenant' => true],
        ]);
    }

    private static function policyWithIntegerKinds(): GuestPolicy
    {
        return self::policy([
            'read' => ['kinds' => [1, 7], 'from_tenants_only' => false],
            'write' => ['kinds' => [7], 'tagged_to_tenant' => false],
        ]);
    }

    private static function policyWithNonIntegerKinds(): GuestPolicy
    {
        return self::policy([
            'read' => ['kinds' => ['junk', 1, '9', 7], 'from_tenants_only' => true],
            'write' => ['kinds' => [null, [], 7], 'tagged_to_tenant' => true],
        ]);
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
                new TagPrefixRequirementCollection([new TagPrefixRequirement(TagType::rootExternalContent(), ['https://www.example.com/'], EventKindCollection::fromInts([EventKind::COMMENT]))]),
            ),
        );

        $restored = self::policy($original->toArray());

        $this->assertEquals(
            $original->getWrite()->getTagPrefixRequirements(),
            $restored->getWrite()->getTagPrefixRequirements(),
        );
    }

    public function testAPolicyWithAnUnparseableTagPrefixRuleDoesNotParse(): void
    {
        $this->assertNull(GuestPolicy::tryFromArray([
            'read' => ['kinds' => [1], 'from_tenants_only' => true],
            'write' => ['kinds' => [1], 'tagged_to_tenant' => false, 'tag_prefixes' => ['tag' => 'I', 'prefixes' => []]],
        ]));
    }

    public function testAPolicyWhoseTagPrefixRuleNamesNoKindsDoesNotParse(): void
    {
        $this->assertNull(GuestPolicy::tryFromArray([
            'write' => ['kinds' => [EventKind::COMMENT], 'tagged_to_tenant' => false, 'tag_prefixes' => [['tag' => 'I', 'prefixes' => ['https://www.example.com/']]]],
        ]));
    }

    public function testAPolicyWhoseTagPrefixesIsNullCarriesNoRule(): void
    {
        $this->assertTrue(self::policy(['write' => ['tag_prefixes' => null]])->getWrite()->getTagPrefixRequirements()->isEmpty());
    }
}
