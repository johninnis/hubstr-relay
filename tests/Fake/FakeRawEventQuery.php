<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Tests\Fake;

use Innis\Hubstr\Relay\Application\Port\RawEventQueryInterface;
use Innis\Hubstr\Relay\Domain\ValueObject\RawEvent;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Filter;
use Override;

final class FakeRawEventQuery implements RawEventQueryInterface
{
    /** @var list<Filter> */
    public array $receivedFilters = [];

    /** @var list<RawEvent> */
    private array $rawEvents;

    public function __construct(RawEvent ...$rawEvents)
    {
        $this->rawEvents = array_values($rawEvents);
    }

    /**
     * @return iterable<RawEvent>
     */
    #[Override]
    public function findRawByFilter(Filter $filter): iterable
    {
        $this->receivedFilters[] = $filter;

        yield from $this->rawEvents;
    }
}
