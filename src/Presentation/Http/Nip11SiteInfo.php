<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Presentation\Http;

use Innis\Hubstr\Core\Application\Port\SiteInfoProviderInterface;
use Innis\Hubstr\Core\Domain\ValueObject\SiteInfo;
use Innis\Nostr\Relay\Application\Port\Nip11InfoProviderInterface;
use Override;

final readonly class Nip11SiteInfo implements SiteInfoProviderInterface
{
    private const string DEFAULT_NAME = 'Nostr Relay';

    public function __construct(
        private Nip11InfoProviderInterface $nip11InfoProvider,
    ) {
    }

    #[Override]
    public function getSiteInfo(): SiteInfo
    {
        $info = $this->nip11InfoProvider->getNip11Info();

        return new SiteInfo(
            $info->getName() ?? self::DEFAULT_NAME,
            $info->getVersion() ?? '',
            $info->getPubkey()?->toBech32(),
        );
    }
}
