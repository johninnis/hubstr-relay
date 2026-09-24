<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Domain\ValueObject;

use Innis\Nostr\Core\Domain\Service\JsonWireFormat;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventKind;

final readonly class KindCount
{
    public function __construct(
        private EventKind $kind,
        private int $count,
    ) {
    }

    /**
     * @param array<array-key, mixed> $data
     */
    public static function tryFromArray(array $data): ?self
    {
        $kindInt = JsonWireFormat::intField($data, 'kind');
        $kind = null === $kindInt ? null : EventKind::tryFromInt($kindInt);
        $count = JsonWireFormat::intField($data, 'count');

        return null === $kind || null === $count ? null : new self($kind, $count);
    }

    public function getKind(): EventKind
    {
        return $this->kind;
    }

    public function getCount(): int
    {
        return $this->count;
    }

    /**
     * @return array{kind: int, count: int}
     */
    public function toArray(): array
    {
        return ['kind' => $this->kind->toInt(), 'count' => $this->count];
    }
}
