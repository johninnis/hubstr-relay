<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Tests\Unit\Domain\Collection;

use Innis\Hubstr\Relay\Domain\Collection\TagPrefixRequirementCollection;
use Innis\Hubstr\Relay\Domain\ValueObject\TagPrefixRequirement;
use Innis\Nostr\Core\Domain\Collection\TagCollection;
use Innis\Nostr\Core\Domain\ValueObject\Tag\Tag;
use Innis\Nostr\Core\Domain\ValueObject\Tag\TagType;
use PHPUnit\Framework\TestCase;

final class TagPrefixRequirementCollectionTest extends TestCase
{
    private const string SITE = 'https://www.example.com/';

    public function testAnEmptyCollectionIsSatisfiedByNothing(): void
    {
        $this->assertFalse(new TagPrefixRequirementCollection()->isSatisfiedBy($this->tags('I', self::SITE.'page')));
    }

    public function testSatisfiedWhenTheUppercaseRootRuleMatches(): void
    {
        $this->assertTrue($this->rootOrParent()->isSatisfiedBy($this->tags('I', self::SITE.'page')));
    }

    public function testSatisfiedWhenTheLowercaseParentRuleMatches(): void
    {
        $this->assertTrue($this->rootOrParent()->isSatisfiedBy($this->tags('i', self::SITE.'page')));
    }

    public function testNotSatisfiedWhenNoRuleMatches(): void
    {
        $this->assertFalse($this->rootOrParent()->isSatisfiedBy($this->tags('I', 'https://elsewhere.example/page')));
    }

    public function testTryFromArrayReadsSeveralRules(): void
    {
        $collection = TagPrefixRequirementCollection::tryFromArray([
            ['tag' => 'I', 'prefixes' => [self::SITE]],
            ['tag' => 'i', 'prefixes' => [self::SITE]],
        ]);

        $this->assertEquals($this->rootOrParent(), $collection);
    }

    public function testTryFromArrayReadsAnEmptyListAsRequiringNothing(): void
    {
        $collection = TagPrefixRequirementCollection::tryFromArray([]);

        $this->assertNotNull($collection);
        $this->assertTrue($collection->isEmpty());
    }

    public function testTryFromArrayRejectsAnEntryThatIsNotARule(): void
    {
        $this->assertNull(TagPrefixRequirementCollection::tryFromArray(['I']));
    }

    public function testTryFromArrayRejectsTheWholeListWhenOneEntryIsMalformed(): void
    {
        $this->assertNull(TagPrefixRequirementCollection::tryFromArray([
            ['tag' => 'I', 'prefixes' => [self::SITE]],
            ['tag' => 'i', 'prefixes' => []],
        ]));
    }

    public function testRoundTripsThroughItsWireForm(): void
    {
        $this->assertEquals(
            $this->rootOrParent(),
            TagPrefixRequirementCollection::tryFromArray($this->rootOrParent()->toWireArray()),
        );
    }

    private function rootOrParent(): TagPrefixRequirementCollection
    {
        return new TagPrefixRequirementCollection([
            new TagPrefixRequirement(TagType::rootExternalContent(), [self::SITE]),
            new TagPrefixRequirement(TagType::externalContent(), [self::SITE]),
        ]);
    }

    private function tags(string $name, string $value): TagCollection
    {
        return new TagCollection([Tag::create($name, $value)]);
    }
}
