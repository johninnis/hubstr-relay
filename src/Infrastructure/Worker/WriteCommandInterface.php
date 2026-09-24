<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Infrastructure\Worker;

// Deliberate: the many one-method commands are not folded into closures — they cross a process boundary and must serialise — see ADR-0011
interface WriteCommandInterface
{
    public function applyTo(WriteContext $context): mixed;
}
