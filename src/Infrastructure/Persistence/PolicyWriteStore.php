<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Infrastructure\Persistence;

use Innis\Hubstr\Relay\Domain\Enum\BlacklistType;
use Innis\Hubstr\Relay\Domain\ValueObject\BlockedIp;
use Innis\Nostr\Core\Application\Port\ClockInterface;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use Innis\Nostr\Core\Infrastructure\Time\SystemClock;
use Innis\Nostr\Relay\Domain\ValueObject\IpAddress;
use PDO;

final readonly class PolicyWriteStore
{
    public function __construct(
        private PDO $pdo,
        private ClockInterface $clock = new SystemClock(),
    ) {
    }

    public function addTenant(PublicKey $pubkey): void
    {
        $this->pdo
            ->prepare('INSERT OR IGNORE INTO tenants (pubkey, created_at) VALUES (?, ?)')
            ->execute([$pubkey->toBytes(), $this->clock->now()->toInt()]);
    }

    // Deliberate: the last-tenant guard lives in the delete itself, the only place it is atomic — see ADR-0021
    public function removeTenantUnlessLast(PublicKey $pubkey): int
    {
        $statement = $this->pdo->prepare('DELETE FROM tenants WHERE pubkey = ? AND (SELECT COUNT(*) FROM tenants) > 1');
        $statement->execute([$pubkey->toBytes()]);

        return $statement->rowCount();
    }

    public function addBlacklistEntry(BlacklistType $type, string $value): void
    {
        $this->pdo
            ->prepare('INSERT OR IGNORE INTO blacklist (type, value, created_at) VALUES (?, ?, ?)')
            ->execute([$type->value, $value, $this->clock->now()->toInt()]);
    }

    public function removeBlacklistEntry(BlacklistType $type, string $value): void
    {
        $this->pdo
            ->prepare('DELETE FROM blacklist WHERE type = ? AND value = ?')
            ->execute([$type->value, $value]);
    }

    public function blockIp(BlockedIp $blocked): void
    {
        $this->pdo
            ->prepare('INSERT OR IGNORE INTO blocked_ips (ip, reason, created_at) VALUES (?, ?, ?)')
            ->execute([(string) $blocked->getIp(), $blocked->getReason(), $this->clock->now()->toInt()]);
    }

    public function unblockIp(IpAddress $ip): void
    {
        $this->pdo
            ->prepare('DELETE FROM blocked_ips WHERE ip = ?')
            ->execute([(string) $ip]);
    }

    public function saveSetting(SettingKey $key, string $value): void
    {
        $this->pdo
            ->prepare('INSERT OR REPLACE INTO settings (key, value, updated_at) VALUES (?, ?, ?)')
            ->execute([$key->value, $value, $this->clock->now()->toInt()]);
    }

    public function deleteSetting(SettingKey $key): void
    {
        $this->pdo
            ->prepare('DELETE FROM settings WHERE key = ?')
            ->execute([$key->value]);
    }
}
