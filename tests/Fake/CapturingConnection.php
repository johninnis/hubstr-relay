<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Tests\Fake;

use Innis\Nostr\Relay\Application\Port\ClientConnectionInterface;
use Override;

final class CapturingConnection implements ClientConnectionInterface
{
    /** @var list<string> */
    public array $messages = [];

    #[Override]
    public function sendText(string $text): void
    {
        $this->messages[] = $text;
    }
}
