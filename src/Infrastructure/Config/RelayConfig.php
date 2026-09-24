<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Infrastructure\Config;

use Innis\Hubstr\Core\Application\Port\VersionProviderInterface;
use Innis\Hubstr\Core\Domain\ValueObject\ConfigValues;
use Innis\Hubstr\Core\Domain\ValueObject\ServiceRuntimeConfig;
use Innis\Hubstr\Core\Infrastructure\Config\ConfigLoader;
use Innis\Hubstr\Core\Infrastructure\Version\ComposerVersionProvider;
use Innis\Hubstr\Relay\Domain\ValueObject\RelayLimits;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Nip11Info;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\RelayUrl;
use Innis\Nostr\Relay\Application\Port\RelayConfigInterface;
use InvalidArgumentException;
use Override;

final readonly class RelayConfig implements RelayConfigInterface
{
    private const array SUPPORTED_NIPS = [1, 9, 11, 40, 42, 45, 50, 70, 86, 98];
    private const string SOFTWARE = 'hubstr-relay';
    private const string ENVIRONMENT_VARIABLE = 'HUBSTR_RELAY_CONFIG';
    private const string DEFAULT_NAME = 'Hubstr Relay';
    private const int DEFAULT_PORT = 8080;
    private const int DEFAULT_MAX_CONNECTIONS = 100;
    private const array KEYS = ['admin_pubkey', 'relay_url', 'name', 'description', 'contact', 'icon', 'connection_limits', 'limits'];
    private const array CONNECTION_LIMIT_KEYS = ['max_connections'];
    private const array LIMIT_KEYS = ['max_subscriptions', 'max_filters', 'max_limit', 'max_content_length'];

    private function __construct(
        private ServiceRuntimeConfig $runtime,
        private PublicKey $adminPubkey,
        private int $maxConnections,
        private Nip11Info $relayInfo,
        private RelayLimits $relayLimits,
    ) {
    }

    public static function load(string $configPath, VersionProviderInterface $versionProvider = new ComposerVersionProvider()): self
    {
        return self::fromValues(new ConfigLoader(self::ENVIRONMENT_VARIABLE)->load($configPath), $versionProvider->getVersion());
    }

    public static function fromValues(ConfigValues $values, ?string $version = null): self
    {
        $values->rejectUnknownKeys(...self::KEYS, ...ServiceRuntimeConfig::KEYS);
        $values->section('connection_limits')->rejectUnknownKeys(...self::CONNECTION_LIMIT_KEYS);
        $values->section('limits')->rejectUnknownKeys(...self::LIMIT_KEYS);

        $adminPubkey = PublicKey::tryFromNpubOrHex($values->string('admin_pubkey'))
            ?? throw new InvalidArgumentException('admin_pubkey must be a public key, as 64 hex characters or an npub');

        $relayUrl = RelayUrl::tryFromString($values->string('relay_url'))
            ?? throw new InvalidArgumentException('relay_url must be a valid WebSocket URL');

        $relayInfo = Nip11Info::fromArray($relayUrl, array_filter([
            'name' => $values->optionalString('name') ?? self::DEFAULT_NAME,
            'description' => $values->optionalString('description'),
            'pubkey' => $adminPubkey->toHex(),
            'contact' => $values->optionalString('contact'),
            'icon' => $values->optionalString('icon'),
            'supported_nips' => self::SUPPORTED_NIPS,
            'software' => self::SOFTWARE,
            'version' => $version,
        ], static fn ($value): bool => null !== $value));

        return new self(
            runtime: ServiceRuntimeConfig::fromValues($values, self::DEFAULT_PORT),
            adminPubkey: $adminPubkey,
            maxConnections: $values->section('connection_limits')->optionalInt('max_connections') ?? self::DEFAULT_MAX_CONNECTIONS,
            relayInfo: $relayInfo,
            relayLimits: self::relayLimits($values->section('limits')),
        );
    }

    private static function relayLimits(ConfigValues $limits): RelayLimits
    {
        $defaults = RelayLimits::defaults();

        return new RelayLimits(
            maxSubscriptions: $limits->optionalInt('max_subscriptions') ?? $defaults->getMaxSubscriptions(),
            maxFilters: $limits->optionalInt('max_filters') ?? $defaults->getMaxFilters(),
            maxLimit: $limits->optionalInt('max_limit') ?? $defaults->getMaxLimit(),
            maxContentLength: $limits->optionalInt('max_content_length') ?? $defaults->getMaxContentLength(),
        );
    }

    #[Override]
    public function getMaxConnections(): int
    {
        return $this->maxConnections;
    }

    public function getRuntime(): ServiceRuntimeConfig
    {
        return $this->runtime;
    }

    public function getAdminPubkey(): PublicKey
    {
        return $this->adminPubkey;
    }

    public function getRelayInfo(): Nip11Info
    {
        return $this->relayInfo;
    }

    #[Override]
    public function getRelayUrl(): RelayUrl
    {
        return $this->relayInfo->getRelayUrl();
    }

    public function getRelayLimits(): RelayLimits
    {
        return $this->relayLimits;
    }
}
