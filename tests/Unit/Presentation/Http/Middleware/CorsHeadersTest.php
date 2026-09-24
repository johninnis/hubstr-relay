<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Tests\Unit\Presentation\Http\Middleware;

use Amp\Http\HttpStatus;
use Amp\Http\Server\Response;
use Innis\Hubstr\Relay\Presentation\Http\Middleware\CorsHeaders;
use PHPUnit\Framework\TestCase;

final class CorsHeadersTest extends TestCase
{
    public function testTheOriginStaysWildcardSoAnyNostrClientCanCallTheManagementApi(): void
    {
        $response = new CorsHeaders()->applyTo(new Response(HttpStatus::OK));

        self::assertSame(
            '*',
            $response->getHeader('access-control-allow-origin'),
            'An origin allowlist adds no protection against the threat model — the NIP-98 signature is the '
            .'authority gate — and breaks legitimate web admin clients (ADR-0001).',
        );
    }

    public function testTheAuthorizationHeaderIsAllowedSoNip98CanBeSent(): void
    {
        $response = new CorsHeaders()->applyTo(new Response(HttpStatus::OK));

        self::assertStringContainsStringIgnoringCase('Authorization', (string) $response->getHeader('access-control-allow-headers'));
    }
}
