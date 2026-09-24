<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Tests\Unit\Application\UseCase;

use Innis\Hubstr\Relay\Application\DTO\ExportCriteria;
use Innis\Hubstr\Relay\Application\UseCase\ExportEventsUseCase;
use Innis\Hubstr\Relay\Domain\ValueObject\RawEvent;
use Innis\Hubstr\Relay\Tests\Fake\FakeRawEventQuery;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventKind;
use Innis\Nostr\Core\Domain\ValueObject\Identity\EventId;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use Innis\Nostr\Core\Domain\ValueObject\Timestamp;
use PHPUnit\Framework\TestCase;

final class ExportEventsUseCaseTest extends TestCase
{
    public function testBuildsASingleFilterWhenNoAuthorOrTaggedCriteriaGiven(): void
    {
        $query = new FakeRawEventQuery();
        $useCase = new ExportEventsUseCase($query);

        iterator_to_array($useCase->export(new ExportCriteria(kind: EventKind::fromInt(1), since: Timestamp::fromInt(100))), false);

        self::assertCount(1, $query->receivedFilters);
        self::assertSame([1], $query->receivedFilters[0]->getKinds()?->toInts());
    }

    public function testBuildsSeparateFiltersForAuthorAndTaggedCriteria(): void
    {
        $query = new FakeRawEventQuery();
        $useCase = new ExportEventsUseCase($query);

        iterator_to_array($useCase->export(new ExportCriteria(
            author: $this->pubkey('a'),
            tagged: $this->pubkey('b'),
        )), false);

        self::assertCount(2, $query->receivedFilters);
        self::assertTrue($query->receivedFilters[0]->hasAuthors());
        self::assertNotNull($query->receivedFilters[1]->getTags());
    }

    public function testYieldsEveryRawEventReturnedByTheStore(): void
    {
        $query = new FakeRawEventQuery(
            $this->rawEvent('a', '{"id":"a"}'),
            $this->rawEvent('b', '{"id":"b"}'),
        );
        $useCase = new ExportEventsUseCase($query);

        $exported = iterator_to_array($useCase->export(new ExportCriteria()), false);

        self::assertSame(['{"id":"a"}', '{"id":"b"}'], $exported);
    }

    public function testDeduplicatesEventsSeenUnderMultipleFilters(): void
    {
        $query = new FakeRawEventQuery($this->rawEvent('d', '{"id":"dup"}'));
        $useCase = new ExportEventsUseCase($query);

        $exported = iterator_to_array($useCase->export(new ExportCriteria(
            author: $this->pubkey('a'),
            tagged: $this->pubkey('b'),
        )), false);

        self::assertSame(['{"id":"dup"}'], $exported);
    }

    private function pubkey(string $fill): PublicKey
    {
        return PublicKey::tryFromHex(str_repeat($fill, 64)) ?? self::fail('invalid pubkey');
    }

    private function rawEvent(string $fill, string $json): RawEvent
    {
        return new RawEvent(EventId::tryFromHex(str_repeat($fill, 64)) ?? self::fail('invalid id'), $json);
    }
}
