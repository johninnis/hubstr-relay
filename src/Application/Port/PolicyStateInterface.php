<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Application\Port;

use Innis\Hubstr\Relay\Domain\Collection\BlacklistWordCollection;
use Innis\Hubstr\Relay\Domain\Collection\BlockedIpCollection;
use Innis\Hubstr\Relay\Domain\ValueObject\GuestPolicy;
use Innis\Hubstr\Relay\Domain\ValueObject\RelayMetadata;
use Innis\Nostr\Core\Domain\Collection\HashtagCollection;
use Innis\Nostr\Core\Domain\Collection\PublicKeyCollection;
use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use Innis\Nostr\Relay\Domain\Service\GuestFilterRules;
use Innis\Nostr\Relay\Domain\ValueObject\RateLimitConfig;

interface PolicyStateInterface
{
    public function getTenantPubkeys(): PublicKeyCollection;

    public function isTenantPubkey(PublicKey $pubkey): bool;

    public function getGuestPolicy(): GuestPolicy;

    public function getGuestFilterRules(): GuestFilterRules;

    public function isEventBlacklisted(Event $event): bool;

    public function getBannedPubkeys(): PublicKeyCollection;

    public function getBannedWords(): BlacklistWordCollection;

    public function getBannedHashtags(): HashtagCollection;

    public function getBlockedIps(): BlockedIpCollection;

    public function getRateLimits(): RateLimitConfig;

    public function getMetadata(): RelayMetadata;
}
