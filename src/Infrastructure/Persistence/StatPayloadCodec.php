<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Infrastructure\Persistence;

use Innis\Hubstr\Relay\Domain\Enum\StatName;
use Innis\Hubstr\Relay\Domain\Exception\MalformedStatPayloadException;
use Innis\Hubstr\Relay\Domain\ValueObject\ExploreEntryInterface;
use Innis\Hubstr\Relay\Domain\ValueObject\StatTotals;
use Innis\Nostr\Core\Domain\Service\JsonWireFormat;

final readonly class StatPayloadCodec
{
    /**
     * @param list<ExploreEntryInterface> $entries
     */
    public function encodeEntries(array $entries): string
    {
        return JsonWireFormat::encode(
            array_map(static fn (ExploreEntryInterface $entry): array => $entry->toArray(), $entries),
            JsonWireFormat::MESSAGE,
        );
    }

    /**
     * @return list<ExploreEntryInterface>
     */
    public function decodeEntries(StatName $stat, string $payload): array
    {
        $rows = JsonWireFormat::decodeArray($payload)
            ?? throw new MalformedStatPayloadException('Cached stat payload is not a JSON array');

        $entries = [];
        foreach ($rows as $row) {
            $entry = is_array($row) ? $stat->deserialise($row) : null;
            if (null !== $entry) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }

    public function encodeTotals(StatTotals $totals): string
    {
        return JsonWireFormat::encode($totals->toArray(), JsonWireFormat::MESSAGE);
    }

    public function decodeTotals(string $payload): StatTotals
    {
        $decoded = JsonWireFormat::decodeArray($payload)
            ?? throw new MalformedStatPayloadException('Cached totals payload is not a JSON object');

        return StatTotals::tryFromArray($decoded)
            ?? throw new MalformedStatPayloadException('Cached totals payload has a malformed count or breakdown');
    }
}
