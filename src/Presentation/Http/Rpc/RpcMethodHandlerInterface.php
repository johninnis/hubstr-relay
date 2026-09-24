<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Presentation\Http\Rpc;

use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;

interface RpcMethodHandlerInterface
{
    /**
     * @return array<string, callable(RpcParams, PublicKey): mixed>
     */
    public function handlers(): array;
}
