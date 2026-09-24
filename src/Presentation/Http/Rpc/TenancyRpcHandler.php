<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Presentation\Http\Rpc;

use Innis\Hubstr\Relay\Application\Port\PolicyManagementInterface;
use Innis\Hubstr\Relay\Application\Port\PolicyStateInterface;
use Innis\Hubstr\Relay\Application\UseCase\RemoveTenantUseCase;
use Innis\Hubstr\Relay\Domain\Collection\TagPrefixRequirementCollection;
use Innis\Hubstr\Relay\Domain\Enum\HubstrRpcMethod;
use Innis\Hubstr\Relay\Domain\Failure\TenantPolicyFailure;
use Innis\Hubstr\Relay\Domain\ValueObject\GuestPolicy;
use Innis\Nostr\Core\Domain\Enum\Nip86Method;
use Override;

final readonly class TenancyRpcHandler implements RpcMethodHandlerInterface
{
    private const string INVALID_TAG_PREFIXES = 'Invalid write.tag_prefixes: a list of rules, each naming a tag and at least one non-empty prefix';

    public function __construct(
        private PolicyStateInterface $policyState,
        private PolicyManagementInterface $policyManagement,
        private RemoveTenantUseCase $removeTenant,
    ) {
    }

    #[Override]
    public function handlers(): array
    {
        return [
            Nip86Method::AllowPubkey->value => $this->allowPubkey(...),
            Nip86Method::UnallowPubkey->value => $this->unallowPubkey(...),
            Nip86Method::ListAllowedPubkeys->value => $this->listAllowedPubkeys(...),
            HubstrRpcMethod::GetGuestPolicy->value => $this->getGuestPolicy(...),
            HubstrRpcMethod::SetGuestPolicy->value => $this->setGuestPolicy(...),
        ];
    }

    private function allowPubkey(RpcParams $params): true|RpcRejection
    {
        $pubkey = $params->pubkey();

        if (null === $pubkey) {
            return RpcRejection::invalidPubkey();
        }

        $this->policyManagement->addTenant($pubkey);

        return true;
    }

    private function unallowPubkey(RpcParams $params): true|RpcRejection
    {
        $pubkey = $params->pubkey();

        if (null === $pubkey) {
            return RpcRejection::invalidPubkey();
        }

        return self::appliedOr($this->removeTenant->remove($pubkey));
    }

    /**
     * @return list<string>
     */
    private function listAllowedPubkeys(): array
    {
        return $this->policyState->getTenantPubkeys()->toHexes();
    }

    /**
     * @return array{read: array{kinds: list<int>, global_kinds: list<int>, from_tenants_only: bool}, write: array{kinds: list<int>, tagged_to_tenant: bool, tag_prefixes: list<array{tag: string, prefixes: list<string>}>}}
     */
    private function getGuestPolicy(): array
    {
        return $this->policyState->getGuestPolicy()->toArray();
    }

    // Deliberate: an unparseable tag prefix rule is refused, never defaulted away — see ADR-0018
    private function setGuestPolicy(RpcParams $params): true|RpcRejection
    {
        $submitted = $params->nested(0)->toArray();

        if (!self::tagPrefixesParse($submitted)) {
            return RpcRejection::badRequest(self::INVALID_TAG_PREFIXES);
        }

        $this->policyManagement->setGuestPolicy(GuestPolicy::fromArray($submitted));

        return true;
    }

    /**
     * @param array<array-key, mixed> $submitted
     */
    private static function tagPrefixesParse(array $submitted): bool
    {
        $write = $submitted['write'] ?? null;
        $declared = is_array($write) ? ($write['tag_prefixes'] ?? null) : null;

        if (null === $declared) {
            return true;
        }

        return is_array($declared) && null !== TagPrefixRequirementCollection::tryFromArray($declared);
    }

    private static function appliedOr(?TenantPolicyFailure $failure): true|RpcRejection
    {
        return null === $failure ? true : RpcRejection::conflict($failure->value);
    }
}
