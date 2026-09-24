<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Domain\Enum;

enum ImportOutcome
{
    case Imported;
    case Skipped;
    case Failed;
}
