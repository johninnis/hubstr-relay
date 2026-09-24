<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Domain\Failure;

enum TenantPolicyFailure: string
{
    case LastTenant = 'Cannot remove the last tenant';
    case ActiveTenant = 'Cannot blacklist an active tenant pubkey';
}
