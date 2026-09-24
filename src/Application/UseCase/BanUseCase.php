<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Application\UseCase;

use Innis\Hubstr\Relay\Application\Port\EventPurgerInterface;
use Innis\Hubstr\Relay\Application\Port\PolicyManagementInterface;
use Innis\Hubstr\Relay\Application\Port\PolicyStateInterface;
use Innis\Hubstr\Relay\Domain\Failure\TenantPolicyFailure;
use Innis\Hubstr\Relay\Domain\ValueObject\BlacklistWord;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use Innis\Nostr\Core\Domain\ValueObject\Tag\Hashtag;

final readonly class BanUseCase
{
    public function __construct(
        private PolicyStateInterface $policyState,
        private PolicyManagementInterface $policyManagement,
        private EventPurgerInterface $purger,
    ) {
    }

    public function banPubkey(PublicKey $pubkey): ?TenantPolicyFailure
    {
        if ($this->policyState->isTenantPubkey($pubkey)) {
            return TenantPolicyFailure::ActiveTenant;
        }

        $this->policyManagement->banPubkey($pubkey);
        $this->purger->purgeByPubkey($pubkey);

        return null;
    }

    public function banWord(BlacklistWord $word): void
    {
        $this->policyManagement->banWord($word);
        $this->purger->purgeByContentMatch($word);
    }

    public function banHashtag(Hashtag $hashtag): void
    {
        $this->policyManagement->banHashtag($hashtag);
        $this->purger->purgeByHashtag($hashtag);
    }
}
