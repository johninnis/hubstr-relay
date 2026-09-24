<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Application\Port;

use Innis\Hubstr\Relay\Domain\ValueObject\BlacklistWord;
use Innis\Hubstr\Relay\Domain\ValueObject\BlockedIp;
use Innis\Hubstr\Relay\Domain\ValueObject\GuestPolicy;
use Innis\Hubstr\Relay\Domain\ValueObject\RelayMetadata;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use Innis\Nostr\Core\Domain\ValueObject\Tag\Hashtag;
use Innis\Nostr\Relay\Domain\ValueObject\IpAddress;
use Innis\Nostr\Relay\Domain\ValueObject\RateLimitConfig;

interface PolicyManagementInterface
{
    public function addTenant(PublicKey $pubkey): void;

    public function removeTenantUnlessLast(PublicKey $pubkey): bool;

    public function banPubkey(PublicKey $pubkey): void;

    public function unbanPubkey(PublicKey $pubkey): void;

    public function banWord(BlacklistWord $word): void;

    public function unbanWord(BlacklistWord $word): void;

    public function banHashtag(Hashtag $hashtag): void;

    public function unbanHashtag(Hashtag $hashtag): void;

    public function blockIp(BlockedIp $blocked): void;

    public function unblockIp(IpAddress $ip): void;

    public function setGuestPolicy(GuestPolicy $policy): void;

    public function setRateLimits(RateLimitConfig $rateLimits): void;

    public function setMetadata(RelayMetadata $metadata): void;
}
