<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Tests\Unit\Domain\ValueObject;

use Innis\Hubstr\Relay\Domain\ValueObject\TagPrefixRequirement;
use Innis\Nostr\Core\Domain\Collection\TagCollection;
use Innis\Nostr\Core\Domain\ValueObject\Tag\Tag;
use Innis\Nostr\Core\Domain\ValueObject\Tag\TagType;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class TagPrefixRequirementTest extends TestCase
{
    private const string SITE = 'https://www.example.com/';

    public function testSatisfiedWhenATagValueCarriesThePrefix(): void
    {
        $this->assertTrue($this->requirement()->isSatisfiedBy($this->tags('I', self::SITE.'john/3/16')));
    }

    public function testNotSatisfiedWhenTheTagIsAbsent(): void
    {
        $this->assertFalse($this->requirement()->isSatisfiedBy(new TagCollection()));
    }

    public function testNotSatisfiedWhenTheValueCarriesADifferentPrefix(): void
    {
        $this->assertFalse($this->requirement()->isSatisfiedBy($this->tags('I', 'https://example.com/john/3/16')));
    }

    public function testSatisfiedWhenTheEventCarriesSeveralTagsAndOneMatches(): void
    {
        $tags = new TagCollection([
            Tag::create('I', 'https://example.com/elsewhere'),
            Tag::create('I', self::SITE.'john/3/16'),
        ]);

        $this->assertTrue($this->requirement()->isSatisfiedBy($tags));
    }

    public function testSatisfiedByAnyOneOfSeveralPrefixes(): void
    {
        $requirement = new TagPrefixRequirement(
            TagType::rootExternalContent(),
            ['https://a.example/', 'https://b.example/'],
        );

        $this->assertTrue($requirement->isSatisfiedBy($this->tags('I', 'https://b.example/page')));
    }

    public function testTheTagNameIsCaseSensitive(): void
    {
        $this->assertFalse($this->requirement()->isSatisfiedBy($this->tags('i', self::SITE.'john/3/16')));
    }

    public function testThePrefixMatchIsCaseSensitive(): void
    {
        $this->assertFalse($this->requirement()->isSatisfiedBy($this->tags('I', 'HTTPS://WWW.BIBLESTR.COM/john/3/16')));
    }

    public function testRejectsAnEmptyPrefixList(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new TagPrefixRequirement(TagType::rootExternalContent(), []);
    }

    public function testRejectsAnEmptyPrefixThatWouldAdmitEveryValue(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new TagPrefixRequirement(TagType::rootExternalContent(), [self::SITE, '']);
    }

    public function testRoundTripsThroughItsArrayForm(): void
    {
        $requirement = $this->requirement();

        $this->assertEquals($requirement, TagPrefixRequirement::tryFromArray($requirement->toArray()));
    }

    public function testArrayFormNamesTheTagAndItsPrefixes(): void
    {
        $this->assertSame(
            ['tag' => 'I', 'prefixes' => [self::SITE]],
            $this->requirement()->toArray(),
        );
    }

    public function testTryFromArrayRejectsAMissingTag(): void
    {
        $this->assertNull(TagPrefixRequirement::tryFromArray(['prefixes' => [self::SITE]]));
    }

    public function testTryFromArrayRejectsAnEmptyTag(): void
    {
        $this->assertNull(TagPrefixRequirement::tryFromArray(['tag' => '', 'prefixes' => [self::SITE]]));
    }

    public function testTryFromArrayRejectsMissingPrefixes(): void
    {
        $this->assertNull(TagPrefixRequirement::tryFromArray(['tag' => 'I']));
    }

    public function testTryFromArrayRejectsAnEmptyPrefixList(): void
    {
        $this->assertNull(TagPrefixRequirement::tryFromArray(['tag' => 'I', 'prefixes' => []]));
    }

    public function testTryFromArrayRejectsANonStringPrefix(): void
    {
        $this->assertNull(TagPrefixRequirement::tryFromArray(['tag' => 'I', 'prefixes' => [self::SITE, 42]]));
    }

    public function testTryFromArrayRejectsAnEmptyPrefix(): void
    {
        $this->assertNull(TagPrefixRequirement::tryFromArray(['tag' => 'I', 'prefixes' => ['']]));
    }

    private function requirement(): TagPrefixRequirement
    {
        return new TagPrefixRequirement(TagType::rootExternalContent(), [self::SITE]);
    }

    private function tags(string $name, string $value): TagCollection
    {
        return new TagCollection([Tag::create($name, $value)]);
    }
}
