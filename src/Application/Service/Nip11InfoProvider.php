<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Application\Service;

use Innis\Hubstr\Relay\Application\Port\PolicyStateInterface;
use Innis\Hubstr\Relay\Domain\ValueObject\RelayLimits;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Nip11Info;
use Innis\Nostr\Relay\Application\Port\Nip11InfoProviderInterface;
use Override;

final readonly class Nip11InfoProvider implements Nip11InfoProviderInterface
{
    private const int DIRECT_MESSAGE_INBOX_NIP = 17;

    public function __construct(
        private Nip11Info $base,
        private PolicyStateInterface $policyState,
        private RelayLimits $limits,
    ) {
    }

    #[Override]
    public function getNip11Info(): Nip11Info
    {
        $overrides = $this->policyState->getMetadata();

        return Nip11Info::fromArray($this->base->getRelayUrl(), array_filter([
            ...$this->base->toArray(),
            'name' => $overrides->getName() ?? $this->base->getName(),
            'description' => $overrides->getDescription() ?? $this->base->getDescription(),
            'icon' => $overrides->getIcon() ?? $this->base->getIcon(),
            'supported_nips' => $this->supportedNips(),
            'limitation' => $this->limitation(),
        ], static fn ($value): bool => null !== $value));
    }

    /**
     * @return list<int>
     */
    private function supportedNips(): array
    {
        $nips = array_values(array_filter($this->base->getSupportedNips() ?? [], is_int(...)));

        if ($this->policyState->getGuestPolicy()->servesAsDirectMessageInbox()) {
            $nips[] = self::DIRECT_MESSAGE_INBOX_NIP;
        }

        sort($nips);

        return array_values(array_unique($nips));
    }

    /**
     * @return array<string, int|bool>
     */
    private function limitation(): array
    {
        return [
            'max_subscriptions' => $this->limits->getMaxSubscriptions(),
            'max_filters' => $this->limits->getMaxFilters(),
            'max_limit' => $this->limits->getMaxLimit(),
            'max_content_length' => $this->limits->getMaxContentLength(),
            'auth_required' => false,
            'payment_required' => false,
            'restricted_writes' => true,
        ];
    }
}
