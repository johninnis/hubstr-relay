<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Tests\Integration\Presentation;

use PHPUnit\Framework\TestCase;

final class StylesheetTest extends TestCase
{
    public function testEveryDeclaredTokenIsUsed(): void
    {
        $css = self::stylesheet();
        preg_match_all('/^\s*(--[a-z0-9-]+)\s*:/m', $css, $declared);

        $unused = array_values(array_filter(
            array_unique($declared[1]),
            static fn (string $token): bool => !str_contains($css, "var({$token})"),
        ));

        $this->assertSame([], $unused);
    }

    public function testKeyboardFocusIsVisible(): void
    {
        $this->assertStringContainsString(':focus-visible', self::stylesheet());
    }

    public function testNoFocusRingIsSuppressedAndNoTransitionIsUnnamed(): void
    {
        $this->assertDoesNotMatchRegularExpression('/outline\s*:\s*(none|0)\b|transition\s*:\s*all\b/', self::stylesheet());
    }

    private static function stylesheet(): string
    {
        $css = file_get_contents(dirname(__DIR__, 3).'/public/css/styles.css');
        self::assertIsString($css);

        return $css;
    }
}
