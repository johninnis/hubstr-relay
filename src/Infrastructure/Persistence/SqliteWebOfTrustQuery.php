<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Infrastructure\Persistence;

use Innis\Hubstr\Relay\Application\Port\WebOfTrustQueryInterface;
use Innis\Hubstr\Relay\Domain\ValueObject\WebOfTrustScore;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use Override;
use PDO;

final readonly class SqliteWebOfTrustQuery implements WebOfTrustQueryInterface
{
    public function __construct(
        private PDO $pdo,
    ) {
    }

    #[Override]
    public function score(PublicKey $user, PublicKey $target): WebOfTrustScore
    {
        $userBin = $user->toBytes();
        $targetBin = $target->toBytes();

        $stmt = $this->pdo->prepare(
            'SELECT
                EXISTS(
                    SELECT 1 FROM profile_follows
                    WHERE follower_pubkey = ? AND followed_pubkey = ?
                ) AS direct_follow,
                (
                    SELECT COUNT(*)
                    FROM profile_follows f1
                    INNER JOIN profile_follows f2
                        ON f1.followed_pubkey = f2.follower_pubkey
                    WHERE f1.follower_pubkey = ?
                      AND f2.followed_pubkey = ?
                ) AS mutual_follow_count'
        );

        $stmt->execute([$userBin, $targetBin, $userBin, $targetBin]);

        $row = (array) $stmt->fetch(PDO::FETCH_ASSOC);
        $directFollow = $row['direct_follow'] ?? null;
        $mutualFollowCount = $row['mutual_follow_count'] ?? null;

        return new WebOfTrustScore(
            $target,
            is_numeric($directFollow) && 1 === (int) $directFollow,
            is_numeric($mutualFollowCount) ? (int) $mutualFollowCount : 0,
        );
    }
}
