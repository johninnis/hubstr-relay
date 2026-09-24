<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Domain\Collection;

use Innis\Hubstr\Relay\Domain\ValueObject\TagPrefixRequirement;
use Innis\Nostr\Core\Domain\Collection\TagCollection;
use Innis\Nostr\Core\Domain\Collection\TypedCollection;
use Override;

/**
 * @extends TypedCollection<TagPrefixRequirement>
 */
final class TagPrefixRequirementCollection extends TypedCollection
{
    #[Override]
    protected function elementType(): string
    {
        return TagPrefixRequirement::class;
    }

    // Deliberate: any one requirement satisfies the list, never all of them — see ADR-0019
    public function isSatisfiedBy(TagCollection $tags): bool
    {
        return array_any(
            $this->toArray(),
            static fn (TagPrefixRequirement $requirement) => $requirement->isSatisfiedBy($tags),
        );
    }

    /**
     * @param array<array-key, mixed> $data
     */
    public static function tryFromArray(array $data): ?self
    {
        $requirements = [];

        foreach ($data as $entry) {
            if (!is_array($entry)) {
                return null;
            }

            $requirement = TagPrefixRequirement::tryFromArray($entry);

            if (null === $requirement) {
                return null;
            }

            $requirements[] = $requirement;
        }

        return new self($requirements);
    }

    /**
     * @return list<array{tag: string, prefixes: list<string>}>
     */
    public function toWireArray(): array
    {
        return array_map(
            static fn (TagPrefixRequirement $requirement) => $requirement->toArray(),
            $this->toArray(),
        );
    }
}
