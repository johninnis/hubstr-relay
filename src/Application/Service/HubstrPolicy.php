<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Application\Service;

use Innis\Hubstr\Relay\Application\Port\PolicyStateInterface;
use Innis\Hubstr\Relay\Domain\ValueObject\GuestWritePolicy;
use Innis\Hubstr\Relay\Domain\ValueObject\RelayLimits;
use Innis\Nostr\Core\Domain\Collection\FilterCollection;
use Innis\Nostr\Core\Domain\Collection\PublicKeyCollection;
use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventKind;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use Innis\Nostr\Core\Domain\ValueObject\Payment\ZapReceipt;
use Innis\Nostr\Relay\Application\Port\RelayPolicyInterface;
use Innis\Nostr\Relay\Application\Service\AuthenticatedSessionsInterface;
use Innis\Nostr\Relay\Domain\Entity\RelayClient;
use Innis\Nostr\Relay\Domain\Service\SubscriptionLimits;
use Innis\Nostr\Relay\Domain\ValueObject\PolicyRejection;
use Innis\Nostr\Relay\Domain\ValueObject\ScopedFilters;
use Override;

// Deliberate: a host implementation of the port, not a reuse of the library's static-config RelayPolicy — see ADR-0023
final readonly class HubstrPolicy implements RelayPolicyInterface
{
    private const string PROTECTED_EVENT_REASON = 'this event may only be published by its author';

    private SubscriptionLimits $subscriptionLimits;

    public function __construct(
        private PolicyStateInterface $policyState,
        private AuthenticatedSessionsInterface $authenticatedSessions,
        private RelayLimits $limits,
    ) {
        $this->subscriptionLimits = $limits->toSubscriptionLimits();
    }

    #[Override]
    public function allowEventSubmission(RelayClient $client, Event $event): ?PolicyRejection
    {
        if ($event->getContent()->getLength() > $this->limits->getMaxContentLength()) {
            return PolicyRejection::blocked('event too large');
        }

        if ($this->policyState->isEventBlacklisted($event)) {
            return PolicyRejection::blocked('event rejected by content filter');
        }

        // Deliberate: ahead of the tenant bypass below, and invalid rather than blocked — a zap receipt that fails NIP-57 is malformed whoever sent it — see ADR-0015
        if ($event->getKind()->is(EventKind::ZAP_RECEIPT)) {
            $receipt = ZapReceipt::tryFromEvent($event);

            if (null === $receipt || null === $receipt->getRecipientPubkey()) {
                return PolicyRejection::invalid('zap receipt failed NIP-57 validation (bolt11 amount missing, mismatched, or no recipient)');
            }
        }

        $authenticatedPubkeys = $this->authenticatedSessions->getAuthenticatedPubkeys($client->getId());
        $authenticated = !$authenticatedPubkeys->isEmpty();

        // Deliberate: ahead of the tenant bypass, because a protected event may be published only by its author, whoever relays it — see ADR-0032
        if ($event->isProtected() && !$authenticatedPubkeys->contains($event->getPubkey())) {
            return $authenticated
                ? PolicyRejection::blocked(self::PROTECTED_EVENT_REASON)
                : PolicyRejection::authRequired(self::PROTECTED_EVENT_REASON);
        }

        if ($this->holdsATenant($authenticatedPubkeys) || $this->isEventFromTenant($event)) {
            return null;
        }

        $writePolicy = $this->policyState->getGuestPolicy()->getWrite();

        if (!$writePolicy->getKinds()->contains($event->getKind())) {
            return $authenticated
                ? PolicyRejection::blocked('event kind not allowed')
                : PolicyRejection::authRequired('authentication required to publish this event kind');
        }

        $unprovenanced = $this->unmetProvenance($writePolicy, $event);

        if (null !== $unprovenanced) {
            return $authenticated
                ? PolicyRejection::blocked($unprovenanced)
                : PolicyRejection::authRequired('authentication required to publish this event');
        }

        return null;
    }

    // Deliberate: the configured provenance checks are alternatives, never conjuncts — see ADR-0019
    private function unmetProvenance(GuestWritePolicy $writePolicy, Event $event): ?string
    {
        $unmet = [];

        if ($writePolicy->isTaggedToTenant()) {
            if ($this->isTaggedToTenant($event)) {
                return null;
            }

            $unmet[] = 'reference a relay tenant';
        }

        $requirements = $writePolicy->getTagPrefixRequirements();

        if (!$requirements->isEmpty()) {
            if ($requirements->isSatisfiedBy($event->getTags())) {
                return null;
            }

            $unmet[] = 'carry a recognised identifier tag';
        }

        return [] === $unmet ? null : 'event must '.implode(' or ', $unmet);
    }

    #[Override]
    public function offersAuthChallenge(RelayClient $client, Event $event): bool
    {
        // Deliberate: an admitted tenant-authored NIP-46 response draws a challenge so the signer can authenticate and become rate-limit exempt — see ADR-0014
        return !$this->isTenant($client)
            && $this->isEventFromTenant($event)
            && $event->getKind()->is(EventKind::NOSTR_CONNECT);
    }

    #[Override]
    public function allowSubscription(RelayClient $client, FilterCollection $filters, int $currentSubscriptionCount): ?PolicyRejection
    {
        if ($this->isTenant($client)) {
            return null;
        }

        return $this->subscriptionLimits->enforce($currentSubscriptionCount, $filters);
    }

    #[Override]
    public function filterForClient(RelayClient $client, FilterCollection $filters): ScopedFilters
    {
        // Deliberate: bounded before the tenant check, so the read ceiling holds for a tenant too — authentication lifts scope, never volume — see ADR-0004
        $bounded = $this->subscriptionLimits->bound($filters);

        if ($this->isTenant($client)) {
            return ScopedFilters::unchanged($bounded);
        }

        return $this->policyState->getGuestFilterRules()->scope($bounded, $this->guestsReadFromTenantsOnly());
    }

    #[Override]
    public function canClientReceiveEvent(RelayClient $client, Event $event): bool
    {
        if ($this->isTenant($client)) {
            return true;
        }

        return $this->policyState->getGuestFilterRules()->allowsEvent($event, $this->guestsReadFromTenantsOnly());
    }

    #[Override]
    public function isRateLimitExempt(RelayClient $client): bool
    {
        return $this->isTenant($client);
    }

    #[Override]
    public function allowsAuthentication(PublicKey $pubkey): ?PolicyRejection
    {
        return $this->policyState->isTenantPubkey($pubkey)
            ? null
            : PolicyRejection::restricted('authentication is limited to relay tenants');
    }

    private function guestsReadFromTenantsOnly(): bool
    {
        return $this->policyState->getGuestPolicy()->getRead()->isFromTenantsOnly();
    }

    private function isEventFromTenant(Event $event): bool
    {
        return $this->policyState->isTenantPubkey($event->getPubkey());
    }

    private function isTenant(RelayClient $client): bool
    {
        return $this->holdsATenant($this->authenticatedSessions->getAuthenticatedPubkeys($client->getId()));
    }

    private function holdsATenant(PublicKeyCollection $pubkeys): bool
    {
        return !$pubkeys->intersect($this->policyState->getTenantPubkeys())->isEmpty();
    }

    private function isTaggedToTenant(Event $event): bool
    {
        return $this->holdsATenant($event->getTags()->getPubkeys());
    }
}
