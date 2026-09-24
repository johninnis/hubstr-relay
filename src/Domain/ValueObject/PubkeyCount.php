<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Domain\ValueObject;

use Innis\Nostr\Core\Domain\Service\JsonWireFormat;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use Override;

final readonly class PubkeyCount implements ExploreEntryInterface
{
    public function __construct(
        private PublicKey $pubkey,
        private int $count,
    ) {
    }

    /**
     * @param array<array-key, mixed> $data
     */
    public static function tryFromArray(array $data): ?self
    {
        $hex = JsonWireFormat::stringField($data, 'pubkey');
        $pubkey = null === $hex ? null : PublicKey::tryFromHex($hex);
        $count = JsonWireFormat::intField($data, 'count');

        return null === $pubkey || null === $count ? null : new self($pubkey, $count);
    }

    public function getPubkey(): PublicKey
    {
        return $this->pubkey;
    }

    #[Override]
    public function getCount(): int
    {
        return $this->count;
    }

    #[Override]
    public function toArray(): array
    {
        return [
            'pubkey' => $this->pubkey->toHex(),
            'count' => $this->count,
        ];
    }
}
