<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Presentation\Http\Rpc;

use Innis\Hubstr\Relay\Application\Port\PolicyManagementInterface;
use Innis\Hubstr\Relay\Application\Port\PolicyStateInterface;
use Innis\Hubstr\Relay\Application\UseCase\BanUseCase;
use Innis\Hubstr\Relay\Domain\Enum\HubstrRpcMethod;
use Innis\Hubstr\Relay\Domain\Failure\TenantPolicyFailure;
use Innis\Hubstr\Relay\Domain\ValueObject\BlacklistWord;
use Innis\Hubstr\Relay\Domain\ValueObject\BlockedIp;
use Innis\Nostr\Core\Domain\Enum\Nip86Method;
use Override;

final readonly class BlockingRpcHandler implements RpcMethodHandlerInterface
{
    private const string MISSING_VALUE = 'Missing required string parameter';
    private const string INVALID_IP = 'Invalid IP address';
    private const string SHORT_WORD = 'A banned word must be at least '.BlacklistWord::MIN_LENGTH.' characters once trimmed';
    private const string REASON_TOO_LONG = 'Block reason exceeds '.BlockedIp::MAX_REASON_LENGTH.' characters';

    public function __construct(
        private PolicyStateInterface $policyState,
        private PolicyManagementInterface $policyManagement,
        private BanUseCase $banUseCase,
    ) {
    }

    #[Override]
    public function handlers(): array
    {
        return [
            Nip86Method::BanPubkey->value => $this->banPubkey(...),
            Nip86Method::UnbanPubkey->value => $this->unbanPubkey(...),
            Nip86Method::ListBannedPubkeys->value => $this->listBannedPubkeys(...),
            HubstrRpcMethod::BanWord->value => $this->banWord(...),
            HubstrRpcMethod::UnbanWord->value => $this->unbanWord(...),
            HubstrRpcMethod::ListBannedWords->value => $this->listBannedWords(...),
            HubstrRpcMethod::BanHashtag->value => $this->banHashtag(...),
            HubstrRpcMethod::UnbanHashtag->value => $this->unbanHashtag(...),
            HubstrRpcMethod::ListBannedHashtags->value => $this->listBannedHashtags(...),
            Nip86Method::BlockIp->value => $this->blockIp(...),
            Nip86Method::UnblockIp->value => $this->unblockIp(...),
            Nip86Method::ListBlockedIps->value => $this->listBlockedIps(...),
        ];
    }

    private function banPubkey(RpcParams $params): true|RpcRejection
    {
        $pubkey = $params->pubkey();

        if (null === $pubkey) {
            return RpcRejection::invalidPubkey();
        }

        return self::appliedOr($this->banUseCase->banPubkey($pubkey));
    }

    private function unbanPubkey(RpcParams $params): true|RpcRejection
    {
        $pubkey = $params->pubkey();

        if (null === $pubkey) {
            return RpcRejection::invalidPubkey();
        }

        $this->policyManagement->unbanPubkey($pubkey);

        return true;
    }

    /**
     * @return list<array{pubkey: string}>
     */
    private function listBannedPubkeys(): array
    {
        return array_map(
            static fn (string $hex): array => ['pubkey' => $hex],
            $this->policyState->getBannedPubkeys()->toHexes(),
        );
    }

    private function banWord(RpcParams $params): true|RpcRejection
    {
        $word = BlacklistWord::tryFromString($params->nonEmptyString(0));

        if (null === $word) {
            return RpcRejection::badRequest(self::SHORT_WORD);
        }

        $this->banUseCase->banWord($word);

        return true;
    }

    private function unbanWord(RpcParams $params): true|RpcRejection
    {
        $word = BlacklistWord::tryFromString($params->nonEmptyString(0));

        if (null === $word) {
            return RpcRejection::badRequest(self::SHORT_WORD);
        }

        $this->policyManagement->unbanWord($word);

        return true;
    }

    /**
     * @return list<string>
     */
    private function listBannedWords(): array
    {
        return $this->policyState->getBannedWords()->toStrings();
    }

    private function banHashtag(RpcParams $params): true|RpcRejection
    {
        $hashtag = $params->hashtag(0);

        if (null === $hashtag) {
            return RpcRejection::badRequest(self::MISSING_VALUE);
        }

        $this->banUseCase->banHashtag($hashtag);

        return true;
    }

    private function unbanHashtag(RpcParams $params): true|RpcRejection
    {
        $hashtag = $params->hashtag(0);

        if (null === $hashtag) {
            return RpcRejection::badRequest(self::MISSING_VALUE);
        }

        $this->policyManagement->unbanHashtag($hashtag);

        return true;
    }

    /**
     * @return list<string>
     */
    private function listBannedHashtags(): array
    {
        return $this->policyState->getBannedHashtags()->toStrings();
    }

    private function blockIp(RpcParams $params): true|RpcRejection
    {
        $ip = $params->ipAddress(0);

        if (null === $ip) {
            return RpcRejection::badRequest(self::INVALID_IP);
        }

        $blocked = BlockedIp::tryFromParts($ip, $params->string(1) ?? '');

        if (null === $blocked) {
            return RpcRejection::badRequest(self::REASON_TOO_LONG);
        }

        $this->policyManagement->blockIp($blocked);

        return true;
    }

    private function unblockIp(RpcParams $params): true|RpcRejection
    {
        $ip = $params->ipAddress(0);

        if (null === $ip) {
            return RpcRejection::badRequest(self::INVALID_IP);
        }

        $this->policyManagement->unblockIp($ip);

        return true;
    }

    /**
     * @return list<array{ip: string, reason: string}>
     */
    private function listBlockedIps(): array
    {
        return array_map(
            static fn (BlockedIp $blocked): array => $blocked->toArray(),
            $this->policyState->getBlockedIps()->toArray(),
        );
    }

    private static function appliedOr(?TenantPolicyFailure $failure): true|RpcRejection
    {
        return null === $failure ? true : RpcRejection::conflict($failure->value);
    }
}
