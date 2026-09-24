<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Presentation\Http;

use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use Innis\Hubstr\Core\Domain\Enum\HttpMethod;
use Innis\Hubstr\Core\Presentation\Http\LandingPageResponder;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Nip11Info;
use Override;

final readonly class RootRequestHandler implements RequestHandler
{
    public function __construct(
        private RequestHandler $relay,
        private RpcHandler $rpc,
        private LandingPageResponder $landing,
    ) {
    }

    #[Override]
    public function handleRequest(Request $request): Response
    {
        if (self::addressesTheRelayProtocol($request)) {
            return $this->relay->handleRequest($request);
        }

        return $this->rpc->handleRequest($request) ?? $this->landing->respond();
    }

    private static function addressesTheRelayProtocol(Request $request): bool
    {
        if (HttpMethod::Get->value !== $request->getMethod()) {
            return false;
        }

        return null !== $request->getHeader('upgrade')
            || str_contains(strtolower($request->getHeader('accept') ?? ''), Nip11Info::MEDIA_TYPE);
    }
}
