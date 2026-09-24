<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Domain\ValueObject;

use Innis\Nostr\Relay\Domain\ValueObject\IpAddress;

final readonly class BlockedIp
{
    public const int MAX_REASON_LENGTH = 2048;

    private function __construct(
        private IpAddress $ip,
        private string $reason,
    ) {
    }

    public static function tryFromParts(IpAddress $ip, string $reason): ?self
    {
        $trimmed = trim($reason);

        return mb_strlen($trimmed) > self::MAX_REASON_LENGTH ? null : new self($ip, $trimmed);
    }

    // Deliberate: no length cap here — the cap is an input-boundary rule tryFromParts applies, and a block already stored stays enforced whatever its reason says — see ADR-0026
    public static function fromParts(IpAddress $ip, string $reason): self
    {
        return new self($ip, trim($reason));
    }

    public function getIp(): IpAddress
    {
        return $this->ip;
    }

    public function getReason(): string
    {
        return $this->reason;
    }

    /**
     * @return array{ip: string, reason: string}
     */
    public function toArray(): array
    {
        return ['ip' => (string) $this->ip, 'reason' => $this->reason];
    }
}
