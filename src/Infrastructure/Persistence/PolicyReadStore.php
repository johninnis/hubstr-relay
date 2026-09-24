<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Infrastructure\Persistence;

use Innis\Hubstr\Relay\Domain\Collection\BlacklistWordCollection;
use Innis\Hubstr\Relay\Domain\Collection\BlockedIpCollection;
use Innis\Hubstr\Relay\Domain\Enum\BlacklistType;
use Innis\Hubstr\Relay\Domain\Exception\MalformedSettingException;
use Innis\Hubstr\Relay\Domain\ValueObject\BlockedIp;
use Innis\Nostr\Core\Domain\Collection\HashtagCollection;
use Innis\Nostr\Core\Domain\Collection\PublicKeyCollection;
use Innis\Nostr\Core\Domain\Service\JsonWireFormat;
use Innis\Nostr\Relay\Domain\ValueObject\IpAddress;
use PDO;

final readonly class PolicyReadStore
{
    public function __construct(
        private PDO $pdo,
    ) {
    }

    public function tenantPubkeys(): PublicKeyCollection
    {
        $stmt = $this->pdo->prepare('SELECT LOWER(HEX(pubkey)) FROM tenants');
        $stmt->execute();

        return PublicKeyCollection::fromHexValues($stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    // Deliberate: a stored fragment shorter than the rule is dropped on load rather than kept, because such a fragment refuses most writes and protects nothing — see ADR-0030
    public function bannedWords(): BlacklistWordCollection
    {
        return BlacklistWordCollection::fromStrings($this->blacklistValues(BlacklistType::Word));
    }

    public function bannedPubkeys(): PublicKeyCollection
    {
        return PublicKeyCollection::fromHexValues($this->blacklistValues(BlacklistType::Pubkey));
    }

    public function bannedHashtags(): HashtagCollection
    {
        return HashtagCollection::fromStrings($this->blacklistValues(BlacklistType::Hashtag));
    }

    /**
     * @return list<mixed>
     */
    private function blacklistValues(BlacklistType $type): array
    {
        $stmt = $this->pdo->prepare('SELECT value FROM blacklist WHERE type = ? ORDER BY created_at');
        $stmt->execute([$type->value]);

        return array_values($stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    public function blockedIps(): BlockedIpCollection
    {
        $stmt = $this->pdo->prepare('SELECT ip, reason FROM blocked_ips ORDER BY created_at');
        $stmt->execute();

        $blocked = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $row = (array) $row;
            $ip = IpAddress::tryFromString((string) $row['ip']);

            // Deliberate: a stored address that no longer parses can match no connection, so it is dropped rather than thrown — see ADR-0026
            if (null === $ip) {
                continue;
            }

            $blocked[] = BlockedIp::fromParts($ip, (string) $row['reason']);
        }

        return new BlockedIpCollection($blocked);
    }

    public function setting(SettingKey $key): ?string
    {
        $stmt = $this->pdo->prepare('SELECT value FROM settings WHERE key = ?');
        $stmt->execute([$key->value]);
        $result = $stmt->fetchColumn();

        return false === $result ? null : (string) $result;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function settingObject(SettingKey $key): ?array
    {
        $json = $this->setting($key);

        if (null === $json) {
            return null;
        }

        $decoded = JsonWireFormat::decodeArray($json);

        if (null === $decoded || ([] !== $decoded && array_is_list($decoded))) {
            throw new MalformedSettingException(sprintf('The stored %s setting is not a JSON object', $key->value));
        }

        return array_filter($decoded, static fn (int|string $key): bool => is_string($key), ARRAY_FILTER_USE_KEY);
    }
}
