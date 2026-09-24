<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Application\UseCase;

use Innis\Hubstr\Relay\Application\Port\PolicyManagementInterface;
use Innis\Hubstr\Relay\Application\Port\PolicyStateInterface;
use Innis\Hubstr\Relay\Domain\Failure\MetadataFailure;
use Innis\Hubstr\Relay\Domain\ValueObject\RelayMetadata;

final readonly class ChangeRelayMetadataUseCase
{
    public function __construct(
        private PolicyStateInterface $policyState,
        private PolicyManagementInterface $policyManagement,
    ) {
    }

    public function changeName(string $name): ?MetadataFailure
    {
        return $this->apply($this->policyState->getMetadata()->withName($name));
    }

    public function changeDescription(string $description): ?MetadataFailure
    {
        return $this->apply($this->policyState->getMetadata()->withDescription($description));
    }

    public function changeIcon(string $icon): ?MetadataFailure
    {
        return $this->apply($this->policyState->getMetadata()->withIcon($icon));
    }

    private function apply(RelayMetadata|MetadataFailure $changed): ?MetadataFailure
    {
        if ($changed instanceof MetadataFailure) {
            return $changed;
        }

        $this->policyManagement->setMetadata($changed);

        return null;
    }
}
