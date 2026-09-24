<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Application\Service;

use Innis\Hubstr\Relay\Application\Port\PolicyStateInterface;
use Innis\Hubstr\Relay\Domain\Failure\TenantAuthFailure;
use Innis\Nostr\Core\Application\Service\Nip98ValidatorInterface;
use Innis\Nostr\Core\Domain\Failure\AuthHeaderFailureInterface;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Nip98Request;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\RelayUrl;

final readonly class TenantAuthenticator
{
    public function __construct(
        private Nip98ValidatorInterface $nip98Validator,
        private RelayUrl $relayUrl,
        private PolicyStateInterface $policyState,
    ) {
    }

    public function authenticate(string $authHeader, string $httpMethod, string $requestBody): PublicKey|AuthHeaderFailureInterface|TenantAuthFailure
    {
        $pubkey = $this->nip98Validator->validateAuthHeader(
            $authHeader,
            Nip98Request::fromBody($this->relayUrl->toHttpUrl(), $httpMethod, $requestBody),
        );

        if (!$pubkey instanceof PublicKey) {
            return $pubkey;
        }

        return $this->policyState->isTenantPubkey($pubkey) ? $pubkey : TenantAuthFailure::NotATenant;
    }
}
