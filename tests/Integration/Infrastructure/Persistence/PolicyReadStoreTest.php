<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Tests\Integration\Infrastructure\Persistence;

use Innis\Hubstr\Core\Infrastructure\Persistence\SchemaMigrator;
use Innis\Hubstr\Core\Infrastructure\Persistence\SqliteDatabase;
use Innis\Hubstr\Relay\Domain\Exception\MalformedSettingException;
use Innis\Hubstr\Relay\Infrastructure\Persistence\PolicyReadStore;
use Innis\Hubstr\Relay\Infrastructure\Persistence\SettingKey;
use Innis\Hubstr\Relay\Infrastructure\Worker\WriteContext;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PolicyReadStoreTest extends TestCase
{
    private PDO $pdo;
    private PolicyReadStore $store;

    protected function setUp(): void
    {
        $this->pdo = SqliteDatabase::inMemory()->connect();
        new SchemaMigrator($this->pdo)->migrate(dirname(__DIR__, 4).'/resources/migrations');
        $this->store = new PolicyReadStore($this->pdo);
    }

    public function testAnAbsentSettingIsNull(): void
    {
        self::assertNull($this->store->settingObject(SettingKey::RateLimits));
    }

    public function testAStoredObjectComesBackKeyedByItsFieldNames(): void
    {
        $this->save(SettingKey::RateLimits, '{"events_per_minute": 10, "subscriptions_per_minute": 5}');

        self::assertSame(
            ['events_per_minute' => 10, 'subscriptions_per_minute' => 5],
            $this->store->settingObject(SettingKey::RateLimits),
        );
    }

    public function testAnEmptyObjectIsAnEmptyArray(): void
    {
        $this->save(SettingKey::GuestPolicy, '{}');

        self::assertSame([], $this->store->settingObject(SettingKey::GuestPolicy));
    }

    #[DataProvider('valuesThatAreNotAJsonObject')]
    public function testAStoredValueThatIsNotAJsonObjectIsAFault(string $stored): void
    {
        $this->save(SettingKey::GuestPolicy, $stored);

        $this->expectException(MalformedSettingException::class);
        $this->expectExceptionMessage('guest_policy');

        $this->store->settingObject(SettingKey::GuestPolicy);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function valuesThatAreNotAJsonObject(): iterable
    {
        yield 'truncated JSON' => ['{"read": '];
        yield 'a scalar' => ['42'];
        yield 'a list' => ['[1, 2]'];
    }

    private function save(SettingKey $key, string $value): void
    {
        WriteContext::forConnection($this->pdo)->getPolicyWriteStore()->saveSetting($key, $value);
    }
}
