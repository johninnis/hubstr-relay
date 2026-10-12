<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Domain\Failure;

enum GuestWriteFailure: string
{
    case KindNotWritable = 'event kind not allowed';
    case NoTenantReference = 'event must reference a relay tenant';
    case NoRecognisedTag = 'event must carry a recognised identifier tag';
    case NoTenantReferenceNorRecognisedTag = 'event must reference a relay tenant or carry a recognised identifier tag';
}
