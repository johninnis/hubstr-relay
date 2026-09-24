<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Domain\ValueObject;

use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;

final readonly class WebOfTrustScore
{
    public function __construct(
        private PublicKey $pubkey,
        private bool $followed,
        private int $mutualFollows,
    ) {
    }

    public function getPubkey(): PublicKey
    {
        return $this->pubkey;
    }

    public function isFollowed(): bool
    {
        return $this->followed;
    }

    public function getMutualFollows(): int
    {
        return $this->mutualFollows;
    }

    public function getDistance(): ?int
    {
        return match (true) {
            $this->followed => 1,
            $this->mutualFollows > 0 => 2,
            default => null,
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'pubkey' => $this->pubkey->toHex(),
            'followed' => $this->followed,
            'mutual_follows' => $this->mutualFollows,
            'distance' => $this->getDistance(),
        ];
    }
}
