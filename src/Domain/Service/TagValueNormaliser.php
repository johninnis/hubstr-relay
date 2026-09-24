<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Domain\Service;

use Innis\Nostr\Core\Domain\ValueObject\Tag\Hashtag;
use Innis\Nostr\Core\Domain\ValueObject\Tag\TagType;

final readonly class TagValueNormaliser
{
    public static function normalise(TagType $type, string $value): string
    {
        $hashtag = $type->is(TagType::HASHTAG) ? Hashtag::tryFromString($value) : null;

        return null === $hashtag ? $value : (string) $hashtag;
    }
}
