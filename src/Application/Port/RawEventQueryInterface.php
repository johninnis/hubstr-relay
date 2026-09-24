<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Application\Port;

use Innis\Hubstr\Relay\Domain\ValueObject\RawEvent;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Filter;

interface RawEventQueryInterface
{
    /**
     * @return iterable<RawEvent>
     */
    public function findRawByFilter(Filter $filter): iterable;
}
