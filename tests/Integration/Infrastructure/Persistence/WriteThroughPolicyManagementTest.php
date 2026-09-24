<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Tests\Integration\Infrastructure\Persistence;

use Innis\Hubstr\Core\Infrastructure\Persistence\SchemaMigrator;
use Innis\Hubstr\Core\Infrastructure\Persistence\SqliteDatabase;
use Innis\Hubstr\Relay\Domain\Collection\TagPrefixRequirementCollection;
use Innis\Hubstr\Relay\Domain\ValueObject\BlacklistWord;
use Innis\Hubstr\Relay\Domain\ValueObject\BlockedIp;
use Innis\Hubstr\Relay\Domain\ValueObject\GuestPolicy;
use Innis\Hubstr\Relay\Domain\ValueObject\GuestReadPolicy;
use Innis\Hubstr\Relay\Domain\ValueObject\GuestWritePolicy;
use Innis\Hubstr\Relay\Domain\ValueObject\RelayMetadata;
use Innis\Hubstr\Relay\Domain\ValueObject\TagPrefixRequirement;
use Innis\Hubstr\Relay\Infrastructure\Persistence\PolicyReadStore;
use Innis\Hubstr\Relay\Infrastructure\Persistence\PolicyState;
use Innis\Hubstr\Relay\Infrastructure\Persistence\WriteThroughPolicyManagement;
use Innis\Hubstr\Relay\Infrastructure\Worker\WriteCoordinator;
use Innis\Hubstr\Relay\Tests\Fake\DirectWriteChannel;
use Innis\Nostr\Core\Domain\Collection\EventKindCollection;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventKind;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use Innis\Nostr\Core\Domain\ValueObject\Tag\Hashtag;
use Innis\Nostr\Core\Domain\ValueObject\Tag\TagType;
use Innis\Nostr\Relay\Domain\Enum\RateLimitMetric;
use Innis\Nostr\Relay\Domain\ValueObject\IpAddress;
use Innis\Nostr\Relay\Domain\ValueObject\RateLimitConfig;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function Amp\async;

final class WriteThroughPolicyManagementTest extends TestCase
{
    private PDO $pdo;
    private PolicyState $state;
    private WriteThroughPolicyManagement $store;

    protected function setUp(): void
    {
        $this->pdo = SqliteDatabase::inMemory()->connect();
        new SchemaMigrator($this->pdo)->migrate(dirname(__DIR__, 4).'/resources/migrations');

        $this->state = new PolicyState(new PolicyReadStore($this->pdo));
        $this->store = new WriteThroughPolicyManagement(
            $this->state,
            new WriteCoordinator(new DirectWriteChannel($this->pdo)),
        );
    }

    private function freshState(): PolicyState
    {
        $state = new PolicyState(new PolicyReadStore($this->pdo));
        $state->loadFromDatabase();

        return $state;
    }

    public function testSeedAdminPubkeyOnFirstBoot(): void
    {
        $pubkey = self::pubkey('aa');
        $this->store->addTenant($pubkey);

        $tenants = $this->state->getTenantPubkeys();
        $this->assertCount(1, $tenants);
        $this->assertTrue($tenants->toArray()[0]->equals($pubkey));
    }

    public function testSeedDoesNotDuplicateOnSubsequentBoots(): void
    {
        $pubkey = self::pubkey('aa');
        $this->store->addTenant($pubkey);
        $this->store->addTenant($pubkey);

        $this->assertCount(1, $this->state->getTenantPubkeys());
    }

    public function testAddAndRemoveTenant(): void
    {
        $pk1 = self::pubkey('aa');
        $pk2 = self::pubkey('bb');

        $this->store->addTenant($pk1);
        $this->store->addTenant($pk2);

        $this->assertCount(2, $this->state->getTenantPubkeys());
        $this->assertTrue($this->state->isTenantPubkey($pk1));

        $this->assertTrue($this->store->removeTenantUnlessLast($pk2));
        $this->assertCount(1, $this->state->getTenantPubkeys());
    }

    public function testTheLastTenantIsKeptInMemoryAndInTheDatabase(): void
    {
        $pk = self::pubkey('aa');
        $this->store->addTenant($pk);

        $this->assertFalse($this->store->removeTenantUnlessLast($pk));
        $this->assertTrue($this->state->isTenantPubkey($pk));
        $this->assertTrue($this->freshState()->isTenantPubkey($pk));
    }

    public function testConcurrentRemovalsNeverEmptyTheTenantSet(): void
    {
        $pk1 = self::pubkey('aa');
        $pk2 = self::pubkey('bb');
        $this->store->addTenant($pk1);
        $this->store->addTenant($pk2);

        $first = async(fn (): bool => $this->store->removeTenantUnlessLast($pk1));
        $second = async(fn (): bool => $this->store->removeTenantUnlessLast($pk2));

        $this->assertSame([true, false], [$first->await(), $second->await()]);
        $this->assertCount(1, $this->state->getTenantPubkeys());
        $this->assertCount(1, $this->freshState()->getTenantPubkeys());
    }

    public function testGetTenantPubkeys(): void
    {
        $pk = self::pubkey('aa');
        $this->store->addTenant($pk);

        $tenants = $this->state->getTenantPubkeys();
        $this->assertCount(1, $tenants);
        $this->assertTrue($tenants->toArray()[0]->equals($pk));
    }

    public function testGuestPolicyPersistsAndLoads(): void
    {
        $policy = new GuestPolicy(
            new GuestReadPolicy(EventKindCollection::fromInts([1, 7]), new EventKindCollection(), false),
            new GuestWritePolicy(EventKindCollection::fromInts([7]), false),
        );
        $this->store->setGuestPolicy($policy);

        $loaded = $this->freshState()->getGuestPolicy();
        $this->assertSame([1, 7], $loaded->getRead()->getKinds()->toInts());
        $this->assertFalse($loaded->getRead()->isFromTenantsOnly());
    }

    public function testTagPrefixRequirementSurvivesAReload(): void
    {
        $requirements = new TagPrefixRequirementCollection([
            new TagPrefixRequirement(TagType::rootExternalContent(), ['https://www.example.com/']),
        ]);
        $this->store->setGuestPolicy(new GuestPolicy(
            GuestReadPolicy::defaults(),
            new GuestWritePolicy(EventKindCollection::fromInts([EventKind::COMMENT]), false, $requirements),
        ));

        $loaded = $this->freshState()->getGuestPolicy();

        $this->assertEquals(
            $requirements,
            $loaded->getWrite()->getTagPrefixRequirements(),
            'The rule is stored as JSON in settings, so a relay restart must bring back the same admission constraint.',
        );
    }

    public function testRateLimitsPersistAndLoad(): void
    {
        $this->store->setRateLimits(new RateLimitConfig(200, 50));

        $freshState = $this->freshState();

        $this->assertSame(200, $freshState->getRateLimits()->perMinute(RateLimitMetric::Events));
        $this->assertSame(50, $freshState->getRateLimits()->perMinute(RateLimitMetric::Subscriptions));
    }

    public function testBlacklistPersistsAndLoads(): void
    {
        $pubkey = self::pubkey('dd');
        $this->store->banWord(BlacklistWord::fromString('spam'));
        $this->store->banPubkey($pubkey);
        $this->store->banHashtag(Hashtag::fromString('nsfw'));

        $freshState = $this->freshState();

        $this->assertSame(['spam'], $freshState->getBannedWords()->toStrings());
        $this->assertSame([$pubkey->toHex()], $freshState->getBannedPubkeys()->toHexes());
        $this->assertSame(['nsfw'], $freshState->getBannedHashtags()->toStrings());
    }

    public function testRemoveBlacklistEntry(): void
    {
        $this->store->banWord(BlacklistWord::fromString('spam'));
        $this->store->unbanWord(BlacklistWord::fromString('spam'));

        $this->assertSame([], $this->state->getBannedWords()->toStrings());
        $this->assertCount(0, $this->state->getBannedPubkeys());
    }

    public function testUnbanWordWithDifferentCaseRemovesPersistedEntry(): void
    {
        $this->store->banWord(BlacklistWord::fromString('Bitcoin'));
        $this->store->unbanWord(BlacklistWord::fromString('bitcoin'));

        $this->assertSame([], $this->freshState()->getBannedWords()->toStrings());
    }

    public function testUnbanHashtagWithDifferentCaseRemovesPersistedEntry(): void
    {
        $this->store->banHashtag(Hashtag::fromString('NSFW'));
        $this->store->unbanHashtag(Hashtag::fromString('nsfw'));

        $this->assertSame([], $this->freshState()->getBannedHashtags()->toStrings());
    }

    public function testBanWordWithDifferentCaseDoesNotDuplicatePersistedEntry(): void
    {
        $this->store->banWord(BlacklistWord::fromString('Bitcoin'));
        $this->store->banWord(BlacklistWord::fromString('bitcoin'));

        $this->assertSame(['bitcoin'], $this->freshState()->getBannedWords()->toStrings());
    }

    public function testBlacklistFilterUpdatesOnAdd(): void
    {
        $pubkey = self::pubkey('dd');
        $this->store->banPubkey($pubkey);

        $this->assertSame([$pubkey->toHex()], $this->state->getBannedPubkeys()->toHexes());
    }

    public function testBlacklistFilterUpdatesOnRemove(): void
    {
        $pubkey = self::pubkey('dd');
        $this->store->banPubkey($pubkey);
        $this->store->unbanPubkey($pubkey);

        $this->assertCount(0, $this->state->getBannedPubkeys());
    }

    public function testBlockedIpDeniesIsIpAllowed(): void
    {
        $this->assertTrue($this->state->isIpAllowed(IpAddress::fromString('1.2.3.4')));

        $this->store->blockIp(BlockedIp::fromParts(IpAddress::fromString('1.2.3.4'), 'spam'));

        $this->assertFalse($this->state->isIpAllowed(IpAddress::fromString('1.2.3.4')));
        $this->assertTrue($this->state->isIpAllowed(IpAddress::fromString('5.6.7.8')));
    }

    public function testUnblockIpRestoresAccess(): void
    {
        $this->store->blockIp(BlockedIp::fromParts(IpAddress::fromString('1.2.3.4'), 'spam'));
        $this->store->unblockIp(IpAddress::fromString('1.2.3.4'));

        $this->assertTrue($this->state->isIpAllowed(IpAddress::fromString('1.2.3.4')));
    }

    public function testBlockedIpsPersistAndLoad(): void
    {
        $this->store->blockIp(BlockedIp::fromParts(IpAddress::fromString('1.2.3.4'), 'spam'));

        $this->assertFalse($this->freshState()->isIpAllowed(IpAddress::fromString('1.2.3.4')));
    }

    public function testClearedMetadataOverrideRevertsAfterReload(): void
    {
        $this->store->setMetadata(RelayMetadata::fromStored('My Relay', null, null));
        $this->store->setMetadata(RelayMetadata::empty());

        $this->assertNull($this->state->getMetadata()->getName());
        $this->assertNull($this->freshState()->getMetadata()->getName());
    }

    public function testMetadataOverridesPersistAndLoad(): void
    {
        $metadata = RelayMetadata::fromStored('My Relay', 'A test relay', 'https://example.com/icon.png');

        $this->store->setMetadata($metadata);

        $this->assertEquals($metadata, $this->freshState()->getMetadata());
    }

    public function testTenantsLoadFromDatabase(): void
    {
        $pk = self::pubkey('ee');
        $this->store->addTenant($pk);

        $tenants = $this->freshState()->getTenantPubkeys();
        $this->assertCount(1, $tenants);
        $this->assertTrue($tenants->toArray()[0]->equals($pk));
    }

    private static function pubkey(string $byte): PublicKey
    {
        return PublicKey::tryFromHex(str_repeat($byte, 32))
            ?? throw new RuntimeException('Invalid pubkey');
    }
}
