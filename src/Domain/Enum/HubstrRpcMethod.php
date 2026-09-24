<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Domain\Enum;

enum HubstrRpcMethod: string
{
    case BanWord = 'banword';
    case UnbanWord = 'unbanword';
    case ListBannedWords = 'listbannedwords';
    case BanHashtag = 'banhashtag';
    case UnbanHashtag = 'unbanhashtag';
    case ListBannedHashtags = 'listbannedhashtags';
    case GetGuestPolicy = 'getguestpolicy';
    case SetGuestPolicy = 'setguestpolicy';
    case GetRateLimits = 'getratelimits';
    case SetRateLimits = 'setratelimits';
    case ListConnections = 'listconnections';
    case GetConnection = 'getconnection';
    case ListSubscriptions = 'listsubscriptions';
    case GetStats = 'getstats';
    case Explore = 'explore';
    case GetWotScore = 'getwotscore';
}
