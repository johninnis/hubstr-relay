<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Domain\Collection;

use Innis\Hubstr\Relay\Domain\ValueObject\KindCount;
use Innis\Nostr\Core\Domain\Collection\TypedCollection;
use Override;

/**
 * @extends TypedCollection<KindCount>
 */
final class KindCountCollection extends TypedCollection
{
    #[Override]
    protected function elementType(): string
    {
        return KindCount::class;
    }

    public static function fromRows(mixed $rows): self
    {
        return self::fromEach($rows, KindCount::tryFromArray(...));
    }

    public static function tryFromArray(mixed $values): ?self
    {
        return self::tryFromEach($values, KindCount::tryFromArray(...));
    }

    /**
     * @return list<array{kind: int, count: int}>
     */
    public function toWireArray(): array
    {
        return $this->mapItems(static fn (KindCount $count): array => $count->toArray());
    }
}
