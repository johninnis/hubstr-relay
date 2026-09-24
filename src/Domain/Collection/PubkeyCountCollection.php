<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Domain\Collection;

use Innis\Hubstr\Relay\Domain\ValueObject\PubkeyCount;
use Innis\Nostr\Core\Domain\Collection\TypedCollection;
use Override;

/**
 * @extends TypedCollection<PubkeyCount>
 */
final class PubkeyCountCollection extends TypedCollection
{
    #[Override]
    protected function elementType(): string
    {
        return PubkeyCount::class;
    }

    private static function tryParse(mixed $value): ?PubkeyCount
    {
        return is_array($value) ? PubkeyCount::tryFromArray($value) : null;
    }

    public static function fromRows(mixed $rows): self
    {
        return self::fromEach($rows, self::tryParse(...));
    }

    public static function tryFromArray(mixed $values): ?self
    {
        return self::tryFromEach($values, self::tryParse(...));
    }

    /**
     * @return list<array<string, int|string>>
     */
    public function toWireArray(): array
    {
        return $this->mapItems(static fn (PubkeyCount $count): array => $count->toArray());
    }
}
