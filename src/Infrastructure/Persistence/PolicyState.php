<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Infrastructure\Persistence;

use Innis\Hubstr\Relay\Application\Port\PolicyStateInterface;
use Innis\Hubstr\Relay\Domain\Collection\BlacklistWordCollection;
use Innis\Hubstr\Relay\Domain\Collection\BlockedIpCollection;
use Innis\Hubstr\Relay\Domain\Service\BlacklistFilter;
use Innis\Hubstr\Relay\Domain\ValueObject\BlacklistWord;
use Innis\Hubstr\Relay\Domain\ValueObject\BlockedIp;
use Innis\Hubstr\Relay\Domain\ValueObject\GuestPolicy;
use Innis\Hubstr\Relay\Domain\ValueObject\RelayMetadata;
use Innis\Nostr\Core\Domain\Collection\HashtagCollection;
use Innis\Nostr\Core\Domain\Collection\PublicKeyCollection;
use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use Innis\Nostr\Core\Domain\ValueObject\Tag\Hashtag;
use Innis\Nostr\Relay\Application\Port\ConnectionGateInterface;
use Innis\Nostr\Relay\Domain\Service\GuestFilterRules;
use Innis\Nostr\Relay\Domain\ValueObject\IpAddress;
use Innis\Nostr\Relay\Domain\ValueObject\RateLimitConfig;
use Override;

// Deliberate: mutable and not readonly — this is an in-process read model the write path refreshes, not a value — see ADR-0006
final class PolicyState implements PolicyStateInterface, ConnectionGateInterface
{
    private const int DEFAULT_EVENTS_PER_MINUTE = 240;
    private const int DEFAULT_SUBSCRIPTIONS_PER_MINUTE = 60;

    private PublicKeyCollection $tenantPubkeys;
    private GuestPolicy $guestPolicy;
    private ?GuestFilterRules $guestFilterRules = null;
    private BlacklistFilter $blacklistFilter;
    private RateLimitConfig $rateLimits;

    /** @var array<string, BlockedIp> */
    private array $blockedIps = [];
    private RelayMetadata $metadata;

    public function __construct(
        private readonly PolicyReadStore $readStore,
    ) {
        $this->tenantPubkeys = new PublicKeyCollection();
        $this->guestPolicy = GuestPolicy::defaults();
        $this->blacklistFilter = new BlacklistFilter();
        $this->rateLimits = new RateLimitConfig(self::DEFAULT_EVENTS_PER_MINUTE, self::DEFAULT_SUBSCRIPTIONS_PER_MINUTE);
        $this->metadata = RelayMetadata::empty();
    }

    public function loadFromDatabase(): void
    {
        $this->loadTenants();
        $this->loadGuestPolicy();
        $this->loadRateLimits();
        $this->loadBlacklist();
        $this->loadBlockedIps();
        $this->loadMetadataOverrides();
    }

    #[Override]
    public function getTenantPubkeys(): PublicKeyCollection
    {
        return $this->tenantPubkeys;
    }

    #[Override]
    public function isTenantPubkey(PublicKey $pubkey): bool
    {
        return $this->tenantPubkeys->contains($pubkey);
    }

    #[Override]
    public function getGuestPolicy(): GuestPolicy
    {
        return $this->guestPolicy;
    }

    #[Override]
    public function getGuestFilterRules(): GuestFilterRules
    {
        $read = $this->guestPolicy->getRead();

        return $this->guestFilterRules ??= new GuestFilterRules(
            $this->tenantPubkeys,
            $read->getKinds(),
            $read->getGlobalKinds(),
        );
    }

    #[Override]
    public function getRateLimits(): RateLimitConfig
    {
        return $this->rateLimits;
    }

    #[Override]
    public function isEventBlacklisted(Event $event): bool
    {
        return $this->blacklistFilter->isBlacklisted($event);
    }

    #[Override]
    public function getBannedPubkeys(): PublicKeyCollection
    {
        return $this->blacklistFilter->getPubkeys();
    }

    #[Override]
    public function getBannedWords(): BlacklistWordCollection
    {
        return $this->blacklistFilter->getWords();
    }

    #[Override]
    public function getBannedHashtags(): HashtagCollection
    {
        return $this->blacklistFilter->getHashtags();
    }

    #[Override]
    public function getBlockedIps(): BlockedIpCollection
    {
        return new BlockedIpCollection(array_values($this->blockedIps));
    }

    #[Override]
    public function isIpAllowed(IpAddress $ipAddress): bool
    {
        return !isset($this->blockedIps[(string) $ipAddress]);
    }

    #[Override]
    public function getMetadata(): RelayMetadata
    {
        return $this->metadata;
    }

    public function addTenant(PublicKey $pubkey): void
    {
        $this->replaceTenants($this->tenantPubkeys->merge(new PublicKeyCollection([$pubkey]))->unique());
    }

    public function removeTenant(PublicKey $pubkey): void
    {
        $this->replaceTenants($this->tenantPubkeys->diff(new PublicKeyCollection([$pubkey])));
    }

    public function setGuestPolicy(GuestPolicy $policy): void
    {
        $this->guestPolicy = $policy;
        $this->guestFilterRules = null;
    }

    public function setRateLimits(RateLimitConfig $rateLimits): void
    {
        $this->rateLimits = $rateLimits;
    }

    public function banPubkey(PublicKey $pubkey): void
    {
        $this->blacklistFilter = $this->blacklistFilter->withPubkey($pubkey);
    }

    public function unbanPubkey(PublicKey $pubkey): void
    {
        $this->blacklistFilter = $this->blacklistFilter->withoutPubkey($pubkey);
    }

    public function banWord(BlacklistWord $word): void
    {
        $this->blacklistFilter = $this->blacklistFilter->withWord($word);
    }

    public function unbanWord(BlacklistWord $word): void
    {
        $this->blacklistFilter = $this->blacklistFilter->withoutWord($word);
    }

    public function banHashtag(Hashtag $hashtag): void
    {
        $this->blacklistFilter = $this->blacklistFilter->withHashtag($hashtag);
    }

    public function unbanHashtag(Hashtag $hashtag): void
    {
        $this->blacklistFilter = $this->blacklistFilter->withoutHashtag($hashtag);
    }

    public function blockIp(BlockedIp $blocked): void
    {
        $this->blockedIps[(string) $blocked->getIp()] = $blocked;
    }

    public function unblockIp(IpAddress $ip): void
    {
        unset($this->blockedIps[(string) $ip]);
    }

    public function setMetadata(RelayMetadata $metadata): void
    {
        $this->metadata = $metadata;
    }

    private function replaceTenants(PublicKeyCollection $tenantPubkeys): void
    {
        $this->tenantPubkeys = $tenantPubkeys;
        $this->guestFilterRules = null;
    }

    private function loadTenants(): void
    {
        $this->replaceTenants($this->readStore->tenantPubkeys());
    }

    private function loadGuestPolicy(): void
    {
        $data = $this->readStore->settingObject(SettingKey::GuestPolicy);

        if (null !== $data) {
            $this->setGuestPolicy(GuestPolicy::fromArray($data));
        }
    }

    private function loadRateLimits(): void
    {
        $data = $this->readStore->settingObject(SettingKey::RateLimits);

        if (null !== $data) {
            $this->rateLimits = RateLimitConfig::tryFromArray($data) ?? $this->rateLimits;
        }
    }

    private function loadBlacklist(): void
    {
        $this->blacklistFilter = new BlacklistFilter(
            $this->readStore->bannedWords(),
            $this->readStore->bannedPubkeys(),
            $this->readStore->bannedHashtags(),
        );
    }

    private function loadBlockedIps(): void
    {
        $this->blockedIps = [];
        foreach ($this->readStore->blockedIps() as $blocked) {
            $this->blockedIps[(string) $blocked->getIp()] = $blocked;
        }
    }

    private function loadMetadataOverrides(): void
    {
        $this->metadata = RelayMetadata::fromStored(
            $this->readStore->setting(SettingKey::RelayName),
            $this->readStore->setting(SettingKey::RelayDescription),
            $this->readStore->setting(SettingKey::RelayIcon),
        );
    }
}
