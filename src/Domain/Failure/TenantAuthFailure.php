<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Domain\Failure;

enum TenantAuthFailure: string
{
    case NotATenant = 'Pubkey is not a relay tenant';
}
