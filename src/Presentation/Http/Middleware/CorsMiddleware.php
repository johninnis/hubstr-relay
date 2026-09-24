<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Presentation\Http\Middleware;

use Amp\Http\HttpStatus;
use Amp\Http\Server\Middleware;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use Innis\Hubstr\Core\Domain\Enum\HttpMethod;
use Override;

final readonly class CorsMiddleware implements Middleware
{
    public function __construct(
        private CorsHeaders $cors,
    ) {
    }

    #[Override]
    public function handleRequest(Request $request, RequestHandler $requestHandler): Response
    {
        if (HttpMethod::Options->value === $request->getMethod()) {
            return $this->cors->applyTo(new Response(HttpStatus::NO_CONTENT));
        }

        return $this->cors->applyTo($requestHandler->handleRequest($request));
    }
}
