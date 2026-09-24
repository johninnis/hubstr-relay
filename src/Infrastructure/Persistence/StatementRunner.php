<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Infrastructure\Persistence;

use PDO;
use PDOStatement;

// Deliberate: no method hands a PDOStatement to a caller — see ADR-0020
final class StatementRunner
{
    /** @var array<string, PDOStatement> */
    private array $statements = [];

    public function __construct(
        private readonly PDO $pdo,
    ) {
    }

    /**
     * @param list<mixed> $params
     */
    public function execute(string $sql, array $params = []): int
    {
        $statement = $this->prepared($sql);
        $statement->execute($params);
        $affected = $statement->rowCount();
        $statement->closeCursor();

        return $affected;
    }

    /**
     * @param list<mixed> $params
     *
     * @return array<array-key, mixed>|null
     */
    public function selectRow(string $sql, array $params = []): ?array
    {
        $statement = $this->prepared($sql);
        $statement->execute($params);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        $statement->closeCursor();

        return is_array($row) ? $row : null;
    }

    private function prepared(string $sql): PDOStatement
    {
        return $this->statements[$sql] ??= $this->pdo->prepare($sql);
    }
}
