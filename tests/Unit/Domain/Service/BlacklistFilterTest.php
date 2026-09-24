<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Tests\Unit\Domain\Service;

use Innis\Hubstr\Relay\Domain\Collection\BlacklistWordCollection;
use Innis\Hubstr\Relay\Domain\Service\BlacklistFilter;
use Innis\Hubstr\Relay\Domain\ValueObject\BlacklistWord;
use Innis\Hubstr\Relay\Tests\Support\SignedEventFactory;
use Innis\Nostr\Core\Domain\Collection\HashtagCollection;
use Innis\Nostr\Core\Domain\Collection\PublicKeyCollection;
use Innis\Nostr\Core\Domain\Collection\TagCollection;
use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventContent;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventKind;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Rumour;
use Innis\Nostr\Core\Domain\ValueObject\Tag\Hashtag;
use Innis\Nostr\Core\Domain\ValueObject\Tag\Tag;
use Innis\Nostr\Core\Domain\ValueObject\Timestamp;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class BlacklistFilterTest extends TestCase
{
    public function testEmptyFilterAllowsEverything(): void
    {
        $filter = new BlacklistFilter();

        $this->assertFalse($filter->isBlacklisted($this->createEvent('Hello world')));
    }

    public function testMatchesBlacklistedPubkey(): void
    {
        $pubkey = self::pubkey('aa');
        $filter = new BlacklistFilter(pubkeys: new PublicKeyCollection([$pubkey]));

        $this->assertTrue($filter->isBlacklisted($this->createEvent('Hello', $pubkey)));
    }

    public function testMatchesBlacklistedWordCaseInsensitive(): void
    {
        $filter = new BlacklistFilter(new BlacklistWordCollection([BlacklistWord::fromString('spam')]));

        $this->assertTrue($filter->isBlacklisted($this->createEvent('This is SPAM content')));
    }

    public function testMatchesBlacklistedHashtagWhateverCaseTheEventWroteIt(): void
    {
        $filter = new BlacklistFilter(hashtags: new HashtagCollection([Hashtag::fromString('nsfw')]));
        $tags = new TagCollection([Tag::tryFromArray(['t', 'NSFW'])]);

        $this->assertTrue($filter->isBlacklisted($this->createEvent('Some content', null, $tags)));
    }

    public function testWithWordAndWithoutWord(): void
    {
        $word = BlacklistWord::fromString('test');

        $filter = new BlacklistFilter()->withWord($word);
        $this->assertSame(['test'], $filter->getWords()->toStrings());

        $filter = $filter->withoutWord($word);
        $this->assertSame([], $filter->getWords()->toStrings());
    }

    public function testWithPubkeyAndWithoutPubkey(): void
    {
        $pubkey = self::pubkey('bb');
        $filter = new BlacklistFilter()->withPubkey($pubkey);
        $this->assertTrue($filter->isPubkeyBlacklisted($pubkey));

        $filter = $filter->withoutPubkey($pubkey);
        $this->assertFalse($filter->isPubkeyBlacklisted($pubkey));
    }

    public function testWithHashtagAndWithoutHashtag(): void
    {
        $hashtag = Hashtag::fromString('test');

        $filter = new BlacklistFilter()->withHashtag($hashtag);
        $this->assertTrue($filter->isHashtagBlacklisted($hashtag));

        $filter = $filter->withoutHashtag($hashtag);
        $this->assertFalse($filter->isHashtagBlacklisted($hashtag));
    }

    public function testDuplicateWithIsIdempotent(): void
    {
        $filter = new BlacklistFilter()
            ->withWord(BlacklistWord::fromString('spam'))
            ->withWord(BlacklistWord::fromString('spam'))
            ->withHashtag(Hashtag::fromString('nsfw'))
            ->withHashtag(Hashtag::fromString('NSFW'));

        $this->assertCount(1, $filter->getWords());
        $this->assertCount(1, $filter->getHashtags());
    }

    public function testImmutability(): void
    {
        $original = new BlacklistFilter();
        $withWord = $original->withWord(BlacklistWord::fromString('spam'));

        $this->assertSame([], $original->getWords()->toStrings());
        $this->assertSame(['spam'], $withWord->getWords()->toStrings());
    }

    private function createEvent(string $content, ?PublicKey $pubkey = null, ?TagCollection $tags = null): Event
    {
        return SignedEventFactory::fromRumour(new Rumour(
            $pubkey ?? self::pubkey('cc'),
            Timestamp::now(),
            EventKind::fromInt(EventKind::TEXT_NOTE),
            $tags ?? new TagCollection(),
            EventContent::fromString($content)
        ));
    }

    private static function pubkey(string $byte): PublicKey
    {
        return PublicKey::tryFromHex(str_repeat($byte, 32))
            ?? throw new RuntimeException('Invalid pubkey');
    }
}
