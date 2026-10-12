<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Tests\Unit\Domain\ValueObject;

use Innis\Hubstr\Relay\Domain\ValueObject\TagPrefixRequirement;
use Innis\Nostr\Core\Domain\Collection\EventKindCollection;
use Innis\Nostr\Core\Domain\Collection\TagCollection;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventKind;
use Innis\Nostr\Core\Domain\ValueObject\Tag\Tag;
use Innis\Nostr\Core\Domain\ValueObject\Tag\TagType;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class TagPrefixRequirementTest extends TestCase
{
    private const string SITE = 'https://www.example.com/';

    public function testSatisfiedWhenATagValueCarriesThePrefix(): void
    {
        $this->assertTrue($this->requirement()->isSatisfiedBy(self::comment(), $this->tags('I', self::SITE.'john/3/16')));
    }

    public function testNotSatisfiedByAnEventOfAKindTheRuleDoesNotList(): void
    {
        $this->assertFalse($this->requirement()->isSatisfiedBy(EventKind::fromInt(EventKind::GIFT_WRAP), $this->tags('I', self::SITE.'john/3/16')));
    }

    public function testSatisfiedByAnyOneOfItsKinds(): void
    {
        $requirement = new TagPrefixRequirement(TagType::externalContent(), [self::SITE], EventKindCollection::fromInts([EventKind::TEXT_NOTE, EventKind::WEBSITE_REACTION]));

        $this->assertTrue($requirement->isSatisfiedBy(EventKind::fromInt(EventKind::WEBSITE_REACTION), $this->tags('i', self::SITE.'john/3/16')));
    }

    public function testNotSatisfiedWhenTheTagIsAbsent(): void
    {
        $this->assertFalse($this->requirement()->isSatisfiedBy(self::comment(), new TagCollection()));
    }

    public function testNotSatisfiedWhenTheValueCarriesADifferentPrefix(): void
    {
        $this->assertFalse($this->requirement()->isSatisfiedBy(self::comment(), $this->tags('I', 'https://example.com/john/3/16')));
    }

    public function testSatisfiedWhenTheEventCarriesSeveralTagsAndOneMatches(): void
    {
        $tags = new TagCollection([
            Tag::fromArray(['I', 'https://example.com/elsewhere']),
            Tag::fromArray(['I', self::SITE.'john/3/16']),
        ]);

        $this->assertTrue($this->requirement()->isSatisfiedBy(self::comment(), $tags));
    }

    public function testSatisfiedByAnyOneOfSeveralPrefixes(): void
    {
        $requirement = new TagPrefixRequirement(
            TagType::rootExternalContent(),
            ['https://a.example/', 'https://b.example/'],
            EventKindCollection::fromInts([EventKind::COMMENT]),
        );

        $this->assertTrue($requirement->isSatisfiedBy(self::comment(), $this->tags('I', 'https://b.example/page')));
    }

    public function testTheTagNameIsCaseSensitive(): void
    {
        $this->assertFalse($this->requirement()->isSatisfiedBy(self::comment(), $this->tags('i', self::SITE.'john/3/16')));
    }

    public function testThePrefixMatchIsCaseSensitive(): void
    {
        $this->assertFalse($this->requirement()->isSatisfiedBy(self::comment(), $this->tags('I', 'HTTPS://WWW.BIBLESTR.COM/john/3/16')));
    }

    public function testRejectsAnEmptyPrefixList(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new TagPrefixRequirement(TagType::rootExternalContent(), [], EventKindCollection::fromInts([EventKind::COMMENT]));
    }

    public function testRejectsAnEmptyPrefixThatWouldAdmitEveryValue(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new TagPrefixRequirement(TagType::rootExternalContent(), [self::SITE, ''], EventKindCollection::fromInts([EventKind::COMMENT]));
    }

    public function testRejectsAnEmptyKindList(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new TagPrefixRequirement(TagType::rootExternalContent(), [self::SITE], new EventKindCollection());
    }

    public function testRoundTripsThroughItsArrayForm(): void
    {
        $requirement = $this->requirement();

        $this->assertEquals($requirement, TagPrefixRequirement::tryFromArray($requirement->toArray()));
    }

    public function testArrayFormNamesTheTagItsPrefixesAndItsKinds(): void
    {
        $this->assertSame(
            ['tag' => 'I', 'prefixes' => [self::SITE], 'kinds' => [EventKind::COMMENT]],
            $this->requirement()->toArray(),
        );
    }

    public function testTryFromArrayRejectsAMissingTag(): void
    {
        $this->assertNull(TagPrefixRequirement::tryFromArray(['prefixes' => [self::SITE], 'kinds' => [EventKind::COMMENT]]));
    }

    public function testTryFromArrayRejectsAnEmptyTag(): void
    {
        $this->assertNull(TagPrefixRequirement::tryFromArray(['tag' => '', 'prefixes' => [self::SITE], 'kinds' => [EventKind::COMMENT]]));
    }

    public function testTryFromArrayRejectsMissingPrefixes(): void
    {
        $this->assertNull(TagPrefixRequirement::tryFromArray(['tag' => 'I', 'kinds' => [EventKind::COMMENT]]));
    }

    public function testTryFromArrayRejectsAnEmptyPrefixList(): void
    {
        $this->assertNull(TagPrefixRequirement::tryFromArray(['tag' => 'I', 'prefixes' => [], 'kinds' => [EventKind::COMMENT]]));
    }

    public function testTryFromArrayRejectsANonStringPrefix(): void
    {
        $this->assertNull(TagPrefixRequirement::tryFromArray(['tag' => 'I', 'prefixes' => [self::SITE, 42], 'kinds' => [EventKind::COMMENT]]));
    }

    public function testTryFromArrayRejectsAnEmptyPrefix(): void
    {
        $this->assertNull(TagPrefixRequirement::tryFromArray(['tag' => 'I', 'prefixes' => [''], 'kinds' => [EventKind::COMMENT]]));
    }

    public function testTryFromArrayRejectsARuleWithoutKinds(): void
    {
        $this->assertNull(TagPrefixRequirement::tryFromArray(['tag' => 'I', 'prefixes' => [self::SITE]]));
    }

    public function testTryFromArrayRejectsAnEmptyKindList(): void
    {
        $this->assertNull(TagPrefixRequirement::tryFromArray(['tag' => 'I', 'prefixes' => [self::SITE], 'kinds' => []]));
    }

    public function testTryFromArrayRejectsAKindThatIsNotAnInteger(): void
    {
        $this->assertNull(TagPrefixRequirement::tryFromArray(['tag' => 'I', 'prefixes' => [self::SITE], 'kinds' => [EventKind::COMMENT, '1']]));
    }

    public function testTryFromArrayRejectsAKindOutsideTheEventKindRange(): void
    {
        $this->assertNull(TagPrefixRequirement::tryFromArray(['tag' => 'I', 'prefixes' => [self::SITE], 'kinds' => [70_000]]));
    }

    public function testTryFromArrayHoldsAKindListedTwiceOnceInTheOrderFirstListed(): void
    {
        $this->assertSame(
            ['tag' => 'I', 'prefixes' => [self::SITE], 'kinds' => [EventKind::COMMENT, EventKind::TEXT_NOTE]],
            TagPrefixRequirement::tryFromArray(['tag' => 'I', 'prefixes' => [self::SITE], 'kinds' => [EventKind::COMMENT, EventKind::TEXT_NOTE, EventKind::COMMENT]])?->toArray(),
        );
    }

    private function requirement(): TagPrefixRequirement
    {
        return new TagPrefixRequirement(TagType::rootExternalContent(), [self::SITE], EventKindCollection::fromInts([EventKind::COMMENT]));
    }

    private static function comment(): EventKind
    {
        return EventKind::fromInt(EventKind::COMMENT);
    }

    private function tags(string $name, string $value): TagCollection
    {
        return new TagCollection([Tag::fromArray([$name, $value])]);
    }
}
