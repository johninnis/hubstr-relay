<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Presentation\Http\Rpc;

use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use Innis\Nostr\Core\Domain\ValueObject\Tag\Hashtag;
use Innis\Nostr\Relay\Domain\ValueObject\IpAddress;

final readonly class RpcParams
{
    /**
     * @param array<array-key, mixed> $values
     */
    public function __construct(private array $values)
    {
    }

    public function pubkey(int $index = 0): ?PublicKey
    {
        $value = $this->string($index);

        return null === $value ? null : PublicKey::tryFromHex($value);
    }

    public function hashtag(int $index): ?Hashtag
    {
        return Hashtag::tryFromString($this->string($index));
    }

    public function ipAddress(int $index): ?IpAddress
    {
        $value = $this->string($index);

        return null === $value ? null : IpAddress::tryFromString($value);
    }

    public function nonEmptyString(int $index): ?string
    {
        $value = $this->string($index);

        return '' === $value ? null : $value;
    }

    public function string(string|int $key): ?string
    {
        $value = $this->values[$key] ?? null;

        return is_scalar($value) ? (string) $value : null;
    }

    public function int(string|int $key): ?int
    {
        $value = $this->values[$key] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }

    public function nested(string|int $key): self
    {
        $value = $this->values[$key] ?? null;

        return new self(is_array($value) ? $value : []);
    }

    /**
     * @return array<array-key, mixed>
     */
    public function toArray(): array
    {
        return $this->values;
    }
}
