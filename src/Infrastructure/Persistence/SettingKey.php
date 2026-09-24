<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Infrastructure\Persistence;

enum SettingKey: string
{
    case GuestPolicy = 'guest_policy';
    case RateLimits = 'rate_limits';
    case RelayName = 'relay_name';
    case RelayDescription = 'relay_description';
    case RelayIcon = 'relay_icon';
}
