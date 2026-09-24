<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Domain\Failure;

use Innis\Hubstr\Relay\Domain\ValueObject\RelayMetadata;

enum MetadataFailure: string
{
    case NameTooLong = 'relay name exceeds '.RelayMetadata::MAX_LENGTH.' characters';
    case DescriptionTooLong = 'relay description exceeds '.RelayMetadata::MAX_LENGTH.' characters';
    case InvalidIcon = 'icon URL must be an absolute http(s) URL of at most '.RelayMetadata::MAX_LENGTH.' characters';
}
