<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Presentation\Http\Middleware;

use Amp\Http\Server\Response;

final readonly class CorsHeaders
{
    // Deliberate: the wildcard origin is not a gap — the NIP-98 signature is the authority gate. See ADR-0001.
    private const array HEADERS = [
        'access-control-allow-origin' => '*',
        'access-control-allow-methods' => 'GET, POST, OPTIONS',
        'access-control-allow-headers' => 'Content-Type, Authorization',
        'access-control-max-age' => '86400',
    ];

    public function applyTo(Response $response): Response
    {
        foreach (self::HEADERS as $name => $value) {
            $response->setHeader($name, $value);
        }

        return $response;
    }
}
