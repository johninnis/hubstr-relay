<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Infrastructure\Persistence;

use Innis\Hubstr\Relay\Application\Port\PolicyManagementInterface;
use Innis\Hubstr\Relay\Domain\Enum\BlacklistType;
use Innis\Hubstr\Relay\Domain\ValueObject\BlacklistWord;
use Innis\Hubstr\Relay\Domain\ValueObject\BlockedIp;
use Innis\Hubstr\Relay\Domain\ValueObject\GuestPolicy;
use Innis\Hubstr\Relay\Domain\ValueObject\RelayMetadata;
use Innis\Hubstr\Relay\Infrastructure\Worker\Command\AddBlacklistEntryCommand;
use Innis\Hubstr\Relay\Infrastructure\Worker\Command\AddTenantCommand;
use Innis\Hubstr\Relay\Infrastructure\Worker\Command\BlockIpCommand;
use Innis\Hubstr\Relay\Infrastructure\Worker\Command\DeleteSettingCommand;
use Innis\Hubstr\Relay\Infrastructure\Worker\Command\RemoveBlacklistEntryCommand;
use Innis\Hubstr\Relay\Infrastructure\Worker\Command\RemoveTenantCommand;
use Innis\Hubstr\Relay\Infrastructure\Worker\Command\SaveSettingCommand;
use Innis\Hubstr\Relay\Infrastructure\Worker\Command\UnblockIpCommand;
use Innis\Hubstr\Relay\Infrastructure\Worker\WriteCoordinator;
use Innis\Nostr\Core\Domain\Service\JsonWireFormat;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use Innis\Nostr\Core\Domain\ValueObject\Tag\Hashtag;
use Innis\Nostr\Relay\Domain\ValueObject\IpAddress;
use Innis\Nostr\Relay\Domain\ValueObject\RateLimitConfig;
use Override;

final readonly class WriteThroughPolicyManagement implements PolicyManagementInterface
{
    public function __construct(
        private PolicyState $state,
        private WriteCoordinator $writeCoordinator,
    ) {
    }

    #[Override]
    public function addTenant(PublicKey $pubkey): void
    {
        if ($this->state->isTenantPubkey($pubkey)) {
            return;
        }

        $this->writeCoordinator->apply(new AddTenantCommand($pubkey));
        $this->state->addTenant($pubkey);
    }

    #[Override]
    public function removeTenantUnlessLast(PublicKey $pubkey): bool
    {
        if (0 === $this->writeCoordinator->applyForInt(new RemoveTenantCommand($pubkey))) {
            return false;
        }

        $this->state->removeTenant($pubkey);

        return true;
    }

    #[Override]
    public function setGuestPolicy(GuestPolicy $policy): void
    {
        $this->writeCoordinator->apply(new SaveSettingCommand(SettingKey::GuestPolicy, JsonWireFormat::encode($policy->toArray(), JsonWireFormat::MESSAGE)));
        $this->state->setGuestPolicy($policy);
    }

    #[Override]
    public function setRateLimits(RateLimitConfig $rateLimits): void
    {
        $this->writeCoordinator->apply(new SaveSettingCommand(SettingKey::RateLimits, JsonWireFormat::encode($rateLimits->toArray(), JsonWireFormat::MESSAGE)));
        $this->state->setRateLimits($rateLimits);
    }

    #[Override]
    public function banPubkey(PublicKey $pubkey): void
    {
        $this->writeCoordinator->apply(new AddBlacklistEntryCommand(BlacklistType::Pubkey, $pubkey->toHex()));
        $this->state->banPubkey($pubkey);
    }

    #[Override]
    public function unbanPubkey(PublicKey $pubkey): void
    {
        $this->writeCoordinator->apply(new RemoveBlacklistEntryCommand(BlacklistType::Pubkey, $pubkey->toHex()));
        $this->state->unbanPubkey($pubkey);
    }

    #[Override]
    public function banWord(BlacklistWord $word): void
    {
        $this->writeCoordinator->apply(new AddBlacklistEntryCommand(BlacklistType::Word, (string) $word));
        $this->state->banWord($word);
    }

    #[Override]
    public function unbanWord(BlacklistWord $word): void
    {
        $this->writeCoordinator->apply(new RemoveBlacklistEntryCommand(BlacklistType::Word, (string) $word));
        $this->state->unbanWord($word);
    }

    #[Override]
    public function banHashtag(Hashtag $hashtag): void
    {
        $this->writeCoordinator->apply(new AddBlacklistEntryCommand(BlacklistType::Hashtag, (string) $hashtag));
        $this->state->banHashtag($hashtag);
    }

    #[Override]
    public function unbanHashtag(Hashtag $hashtag): void
    {
        $this->writeCoordinator->apply(new RemoveBlacklistEntryCommand(BlacklistType::Hashtag, (string) $hashtag));
        $this->state->unbanHashtag($hashtag);
    }

    #[Override]
    public function blockIp(BlockedIp $blocked): void
    {
        $this->writeCoordinator->apply(new BlockIpCommand($blocked));
        $this->state->blockIp($blocked);
    }

    #[Override]
    public function unblockIp(IpAddress $ip): void
    {
        $this->writeCoordinator->apply(new UnblockIpCommand($ip));
        $this->state->unblockIp($ip);
    }

    #[Override]
    public function setMetadata(RelayMetadata $metadata): void
    {
        $this->persistOverride(SettingKey::RelayName, $metadata->getName());
        $this->persistOverride(SettingKey::RelayDescription, $metadata->getDescription());
        $this->persistOverride(SettingKey::RelayIcon, $metadata->getIcon());
        $this->state->setMetadata($metadata);
    }

    private function persistOverride(SettingKey $key, ?string $value): void
    {
        $this->writeCoordinator->apply(
            null === $value ? new DeleteSettingCommand($key) : new SaveSettingCommand($key, $value),
        );
    }
}
