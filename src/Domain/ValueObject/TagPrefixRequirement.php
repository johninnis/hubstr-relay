<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Domain\ValueObject;

use Innis\Nostr\Core\Domain\Collection\EventKindCollection;
use Innis\Nostr\Core\Domain\Collection\TagCollection;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventKind;
use Innis\Nostr\Core\Domain\ValueObject\Tag\TagType;
use InvalidArgumentException;

final readonly class TagPrefixRequirement
{
    /**
     * @param list<string> $prefixes
     */
    public function __construct(
        private TagType $tag,
        private array $prefixes,
        private EventKindCollection $kinds,
    ) {
        if (!self::isUsable($this->prefixes)) {
            throw new InvalidArgumentException('A tag prefix requirement needs at least one prefix, and no prefix may be empty');
        }

        if ($this->kinds->isEmpty()) {
            throw new InvalidArgumentException('A tag prefix requirement needs at least one kind');
        }
    }

    // Deliberate: a rule admits only the kinds it lists, so a rule meant for one kind of event cannot admit another that carries its tag — see ADR-0044
    public function isSatisfiedBy(EventKind $kind, TagCollection $tags): bool
    {
        return $this->kinds->contains($kind) && array_any(
            $tags->getValuesByType($this->tag),
            fn (string $value) => array_any(
                $this->prefixes,
                static fn (string $prefix) => str_starts_with($value, $prefix),
            ),
        );
    }

    /**
     * @return array{tag: string, prefixes: list<string>, kinds: list<int>}
     */
    public function toArray(): array
    {
        return ['tag' => (string) $this->tag, 'prefixes' => $this->prefixes, 'kinds' => $this->kinds->toInts()];
    }

    /**
     * @param array<array-key, mixed> $data
     */
    public static function tryFromArray(array $data): ?self
    {
        $tag = $data['tag'] ?? null;

        if (!is_string($tag) || '' === $tag) {
            return null;
        }

        $prefixes = $data['prefixes'] ?? null;

        if (!is_array($prefixes)) {
            return null;
        }

        $strings = array_values(array_filter($prefixes, is_string(...)));

        if (count($strings) !== count($prefixes) || !self::isUsable($strings)) {
            return null;
        }

        $kinds = EventKindCollection::tryFromArray($data['kinds'] ?? null)?->unique();

        if (null === $kinds || $kinds->isEmpty()) {
            return null;
        }

        return new self(TagType::fromString($tag), $strings, $kinds);
    }

    /**
     * @param list<string> $prefixes
     */
    private static function isUsable(array $prefixes): bool
    {
        return [] !== $prefixes && !in_array('', $prefixes, true);
    }
}
