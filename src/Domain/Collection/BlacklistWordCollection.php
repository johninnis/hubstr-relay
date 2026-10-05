<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Domain\Collection;

use Innis\Hubstr\Relay\Domain\ValueObject\BlacklistWord;
use Innis\Nostr\Core\Domain\Collection\KeyedCollection;
use Override;

/**
 * @extends KeyedCollection<BlacklistWord>
 */
final class BlacklistWordCollection extends KeyedCollection
{
    #[Override]
    protected function elementType(): string
    {
        return BlacklistWord::class;
    }

    public static function fromStrings(mixed $values): self
    {
        return self::fromEach($values, BlacklistWord::tryFromString(...));
    }

    /**
     * @return list<string>
     */
    public function toStrings(): array
    {
        return $this->mapItems(static fn (BlacklistWord $word): string => (string) $word);
    }
}
