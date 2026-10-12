<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Tests\Fake;

use Override;
use PDO;
use PDOStatement;

final class PlaceholderCountingPdo extends PDO
{
    private int $mostPlaceholders = 0;

    public function __construct()
    {
        parent::__construct('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }

    /**
     * @param array<int|string, mixed> $options
     */
    #[Override]
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $this->mostPlaceholders = max($this->mostPlaceholders, substr_count($query, '?'));

        return parent::prepare($query, $options);
    }

    public function mostPlaceholders(): int
    {
        return $this->mostPlaceholders;
    }
}
