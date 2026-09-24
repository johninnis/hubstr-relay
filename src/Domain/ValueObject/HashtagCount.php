<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Domain\ValueObject;

use Innis\Nostr\Core\Domain\Service\JsonWireFormat;
use Innis\Nostr\Core\Domain\ValueObject\Tag\Hashtag;
use Override;

final readonly class HashtagCount implements ExploreEntryInterface
{
    public function __construct(
        private Hashtag $hashtag,
        private int $count,
    ) {
    }

    /**
     * @param array<array-key, mixed> $data
     */
    public static function tryFromArray(array $data): ?self
    {
        $hashtag = Hashtag::tryFromString($data['hashtag'] ?? null);
        $count = JsonWireFormat::intField($data, 'count');

        return null === $hashtag || null === $count ? null : new self($hashtag, $count);
    }

    public function getHashtag(): Hashtag
    {
        return $this->hashtag;
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
            'hashtag' => (string) $this->hashtag,
            'count' => $this->count,
        ];
    }
}
