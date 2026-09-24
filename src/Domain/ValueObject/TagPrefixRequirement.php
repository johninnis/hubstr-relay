<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Domain\ValueObject;

use Innis\Nostr\Core\Domain\Collection\TagCollection;
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
    ) {
        if (!self::isUsable($this->prefixes)) {
            throw new InvalidArgumentException('A tag prefix requirement needs at least one prefix, and no prefix may be empty');
        }
    }

    public function isSatisfiedBy(TagCollection $tags): bool
    {
        return array_any(
            $tags->getValuesByType($this->tag),
            fn (string $value) => array_any(
                $this->prefixes,
                static fn (string $prefix) => str_starts_with($value, $prefix),
            ),
        );
    }

    /**
     * @return array{tag: string, prefixes: list<string>}
     */
    public function toArray(): array
    {
        return ['tag' => (string) $this->tag, 'prefixes' => $this->prefixes];
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

        return new self(TagType::fromString($tag), $strings);
    }

    /**
     * @param list<string> $prefixes
     */
    private static function isUsable(array $prefixes): bool
    {
        return [] !== $prefixes && !in_array('', $prefixes, true);
    }
}
