<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Presentation\Http;

use Amp\Http\HttpStatus;
use Amp\Http\Server\Request;
use Amp\Http\Server\Response;
use Innis\Hubstr\Core\Domain\Enum\HttpMethod;
use Innis\Hubstr\Relay\Application\Service\TenantAuthenticator;
use Innis\Hubstr\Relay\Domain\Failure\TenantAuthFailure;
use Innis\Hubstr\Relay\Presentation\Http\Rpc\RpcMethodHandlerInterface;
use Innis\Hubstr\Relay\Presentation\Http\Rpc\RpcParams;
use Innis\Hubstr\Relay\Presentation\Http\Rpc\RpcRejection;
use Innis\Nostr\Core\Domain\Enum\Nip86Method;
use Innis\Nostr\Core\Domain\Enum\ReasonPrefix;
use Innis\Nostr\Core\Domain\Failure\AuthHeaderFailureInterface;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Nip86Request;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Nip86Response;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

final readonly class RpcHandler
{
    private const string MALFORMED_BODY = 'malformed request body: expected {"method": "<name>", "params": [...]}';

    /**
     * @var array<string, callable(RpcParams, PublicKey): mixed>
     */
    private array $handlersByMethod;

    /**
     * @param iterable<RpcMethodHandlerInterface> $methodHandlers
     */
    public function __construct(
        private TenantAuthenticator $tenantAuthenticator,
        iterable $methodHandlers,
        private LoggerInterface $logger = new NullLogger(),
    ) {
        $handlersByMethod = [];
        foreach ($methodHandlers as $methodHandler) {
            foreach ($methodHandler->handlers() as $method => $handler) {
                if (Nip86Method::SupportedMethods->value === $method || isset($handlersByMethod[$method])) {
                    throw new InvalidArgumentException("duplicate RPC method registration: {$method}");
                }
                $handlersByMethod[$method] = $handler;
            }
        }
        $this->handlersByMethod = $handlersByMethod;
    }

    public function handleRequest(Request $request): ?Response
    {
        if (HttpMethod::Post->value !== $request->getMethod()) {
            return null;
        }

        if (!str_contains(strtolower($request->getHeader('content-type') ?? ''), Nip86Request::MEDIA_TYPE)) {
            return null;
        }

        $result = $this->handleRpc($request);

        return $result instanceof RpcRejection
            ? $this->jsonResponse(Nip86Response::failure($result->getMessage()), $result->getStatus())
            : $this->jsonResponse(Nip86Response::success($result));
    }

    private function handleRpc(Request $request): mixed
    {
        $authHeader = $request->getHeader('authorization');
        if (null === $authHeader) {
            return RpcRejection::unauthorised(ReasonPrefix::AuthRequired->format('missing Authorization header'));
        }

        $body = $request->getBody()->buffer();
        $tenant = $this->tenantAuthenticator->authenticate($authHeader, HttpMethod::Post->value, $body);

        if ($tenant instanceof AuthHeaderFailureInterface) {
            return RpcRejection::unauthorised(ReasonPrefix::AuthRequired->format($tenant->message()));
        }

        if ($tenant instanceof TenantAuthFailure) {
            return RpcRejection::forbidden($tenant->value);
        }

        $rpcRequest = Nip86Request::tryFromJson($body);

        if (null === $rpcRequest) {
            return RpcRejection::badRequest(self::MALFORMED_BODY);
        }

        return $this->dispatch($rpcRequest, $tenant);
    }

    private function dispatch(Nip86Request $rpcRequest, PublicKey $tenant): mixed
    {
        $method = $rpcRequest->getMethod();

        if (Nip86Method::SupportedMethods->value === $method) {
            return $this->supportedMethods();
        }

        $params = new RpcParams($rpcRequest->getParams());
        $handler = $this->handlersByMethod[$method] ?? null;

        if (null === $handler) {
            return RpcRejection::badRequest("unknown method: {$method}");
        }

        try {
            return $handler($params, $tenant);
        } catch (Throwable $e) {
            $this->logger->error('RPC method failed', ['method' => $method, 'exception' => $e]);

            return RpcRejection::serverError();
        }
    }

    /**
     * @return list<string>
     */
    private function supportedMethods(): array
    {
        $methods = array_keys($this->handlersByMethod);
        $methods[] = Nip86Method::SupportedMethods->value;
        sort($methods);

        return $methods;
    }

    private function jsonResponse(Nip86Response $response, int $status = HttpStatus::OK): Response
    {
        return new Response(
            $status,
            ['content-type' => Nip86Request::MEDIA_TYPE],
            $response->toJson(),
        );
    }
}
