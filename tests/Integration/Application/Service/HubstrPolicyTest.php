<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Tests\Integration\Application\Service;

use Innis\Hubstr\Core\Infrastructure\Persistence\SchemaMigrator;
use Innis\Hubstr\Core\Infrastructure\Persistence\SqliteDatabase;
use Innis\Hubstr\Relay\Application\Service\HubstrPolicy;
use Innis\Hubstr\Relay\Domain\Collection\TagPrefixRequirementCollection;
use Innis\Hubstr\Relay\Domain\ValueObject\BlacklistWord;
use Innis\Hubstr\Relay\Domain\ValueObject\GuestPolicy;
use Innis\Hubstr\Relay\Domain\ValueObject\GuestReadPolicy;
use Innis\Hubstr\Relay\Domain\ValueObject\GuestWritePolicy;
use Innis\Hubstr\Relay\Domain\ValueObject\RelayLimits;
use Innis\Hubstr\Relay\Domain\ValueObject\TagPrefixRequirement;
use Innis\Hubstr\Relay\Infrastructure\Persistence\EventWriteStore;
use Innis\Hubstr\Relay\Infrastructure\Persistence\PolicyReadStore;
use Innis\Hubstr\Relay\Infrastructure\Persistence\PolicyState;
use Innis\Hubstr\Relay\Infrastructure\Persistence\WriteThroughPolicyManagement;
use Innis\Hubstr\Relay\Infrastructure\Worker\WriteContext;
use Innis\Hubstr\Relay\Infrastructure\Worker\WriteCoordinator;
use Innis\Hubstr\Relay\Tests\Fake\CapturingConnection;
use Innis\Hubstr\Relay\Tests\Fake\DirectWriteChannel;
use Innis\Hubstr\Relay\Tests\Support\SignedEventFactory;
use Innis\Nostr\Core\Domain\Collection\EventCollection;
use Innis\Nostr\Core\Domain\Collection\EventCoordinateCollection;
use Innis\Nostr\Core\Domain\Collection\EventIdCollection;
use Innis\Nostr\Core\Domain\Collection\EventKindCollection;
use Innis\Nostr\Core\Domain\Collection\FilterCollection;
use Innis\Nostr\Core\Domain\Collection\TagCollection;
use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\Service\EventValidator;
use Innis\Nostr\Core\Domain\Service\NipComplianceValidator;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventContent;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventKind;
use Innis\Nostr\Core\Domain\ValueObject\Identity\KeyPair;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\EventCount;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Filter;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Relay\AuthMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Relay\OkMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\RelayMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Rumour;
use Innis\Nostr\Core\Domain\ValueObject\Tag\Hashtag;
use Innis\Nostr\Core\Domain\ValueObject\Tag\Tag;
use Innis\Nostr\Core\Domain\ValueObject\Tag\TagType;
use Innis\Nostr\Core\Domain\ValueObject\Timestamp;
use Innis\Nostr\Core\Infrastructure\Crypto\NativeRandomBytesGenerator;
use Innis\Nostr\Core\Infrastructure\Time\SystemClock;
use Innis\Nostr\Relay\Application\Port\MetricsCollectorInterface;
use Innis\Nostr\Relay\Application\Port\RateLimiterInterface;
use Innis\Nostr\Relay\Application\Port\RelayEventStoreInterface;
use Innis\Nostr\Relay\Application\Service\AcceptedEventPipeline;
use Innis\Nostr\Relay\Application\Service\AcceptedEventPublisher;
use Innis\Nostr\Relay\Application\Service\AuthChallengeIssuer;
use Innis\Nostr\Relay\Application\Service\ClientMessenger;
use Innis\Nostr\Relay\Application\Service\EventAdmission;
use Innis\Nostr\Relay\Application\Service\EventDeletionProcessor;
use Innis\Nostr\Relay\Application\Service\EventDistributor;
use Innis\Nostr\Relay\Application\Service\InMemoryAuthenticationRegistry;
use Innis\Nostr\Relay\Application\Service\InMemoryClientRegistry;
use Innis\Nostr\Relay\Application\Service\InMemorySubscriptionRegistry;
use Innis\Nostr\Relay\Application\Service\RateLimitGate;
use Innis\Nostr\Relay\Application\UseCase\ProcessEventSubmissionUseCase;
use Innis\Nostr\Relay\Domain\Entity\RelayClient;
use Innis\Nostr\Relay\Domain\Enum\EventStoreOutcome;
use Innis\Nostr\Relay\Domain\ValueObject\ClientId;
use Innis\Nostr\Relay\Domain\ValueObject\ConnectionInfo;
use Innis\Nostr\Relay\Domain\ValueObject\IpAddress;
use Innis\Nostr\Relay\Infrastructure\Concurrency\AmphpDeferredExecutor;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Revolt\EventLoop;

final class HubstrPolicyTest extends TestCase
{
    private const string SITE = 'https://www.example.com/';

    private PDO $pdo;
    private KeyPair $keyPair;
    private PolicyState $policyState;
    private WriteThroughPolicyManagement $policyManagement;
    private InMemoryAuthenticationRegistry $authManager;
    private HubstrPolicy $policy;
    private PublicKey $tenantPubkey;
    private PublicKey $guestPubkey;
    private InMemorySubscriptionRegistry $subscriptionManager;
    private InMemoryClientRegistry $clientManager;

    protected function setUp(): void
    {
        $this->tenantPubkey = SignedEventFactory::pubkey('aa');
        $this->guestPubkey = SignedEventFactory::pubkey('bb');
        $this->keyPair = KeyPair::generate(SignedEventFactory::signer());

        $this->pdo = SqliteDatabase::inMemory()->connect();
        new SchemaMigrator($this->pdo)->migrate(dirname(__DIR__, 4).'/resources/migrations');

        $this->policyState = new PolicyState(new PolicyReadStore($this->pdo));
        $this->policyManagement = new WriteThroughPolicyManagement(
            $this->policyState,
            new WriteCoordinator(new DirectWriteChannel($this->pdo)),
        );
        $this->policyManagement->addTenant($this->tenantPubkey);

        $this->authManager = new InMemoryAuthenticationRegistry(new NativeRandomBytesGenerator());
        $this->policy = new HubstrPolicy($this->policyState, $this->authManager, RelayLimits::defaults());

        $metrics = $this->createStub(MetricsCollectorInterface::class);
        $this->subscriptionManager = new InMemorySubscriptionRegistry($metrics, new NullLogger());
        $this->clientManager = new InMemoryClientRegistry($metrics, new NativeRandomBytesGenerator(), new NullLogger());
    }

    public function testTenantCanSubmitAnyEvent(): void
    {
        $client = $this->createAuthenticatedTenantClient();
        $event = $this->createEvent(EventKind::fromInt(4), $this->tenantPubkey);

        $this->assertNull($this->policy->allowEventSubmission($client, $event));
    }

    public function testGuestConnectionPublishingTenantAuthoredNip46ResponseIsAcceptedAndDrawsAuthChallenge(): void
    {
        $client = $this->createGuestClient();
        $event = $this->createEvent(EventKind::fromInt(EventKind::NOSTR_CONNECT), $this->tenantPubkey);

        $this->assertNull($this->policy->allowEventSubmission($client, $event));

        $this->assertTrue($this->policy->offersAuthChallenge($client, $event));
    }

    public function testAuthenticatedTenantNip46ResponseIsAcceptedWithoutAuthChallenge(): void
    {
        $client = $this->createAuthenticatedTenantClient();
        $event = $this->createEvent(EventKind::fromInt(EventKind::NOSTR_CONNECT), $this->tenantPubkey);

        $this->assertNull($this->policy->allowEventSubmission($client, $event));

        $this->assertFalse($this->policy->offersAuthChallenge($client, $event));
    }

    public function testGuestTenantAuthoredNonNip46EventIsAcceptedWithoutAuthChallenge(): void
    {
        $client = $this->createGuestClient();
        $event = $this->createEvent(EventKind::fromInt(EventKind::TEXT_NOTE), $this->tenantPubkey);

        $this->assertNull($this->policy->allowEventSubmission($client, $event));

        $this->assertFalse($this->policy->offersAuthChallenge($client, $event));
    }

    public function testGuestOwnNip46RequestOffersNoAuthChallenge(): void
    {
        $client = $this->createGuestClient();
        $event = $this->createEvent(EventKind::fromInt(EventKind::NOSTR_CONNECT), $this->guestPubkey);

        $this->assertFalse($this->policy->offersAuthChallenge($client, $event));
    }

    public function testUnauthenticatedTenantNip46ResponseIsAdmittedAndDrawsAnAuthChallengeOnTheWire(): void
    {
        $this->policyManagement->addTenant($this->keyPair->getPublicKey());
        $event = SignedEventFactory::signedEvent(
            $this->keyPair,
            EventKind::fromInt(EventKind::NOSTR_CONNECT),
            'ciphertext',
            new TagCollection([Tag::pubkey($this->guestPubkey)]),
        );
        $connection = new CapturingConnection();

        $messages = $this->createSubmissionUseCase()->execute($this->createPipelineClient($connection), $event);
        EventLoop::run();

        $ok = $this->capturedOkMessage($messages);
        $this->assertTrue($ok[2]);
        $this->assertTrue($this->returnedAuthChallenge($messages));
    }

    public function testEventExceedingMaxSizeIsRejectedEvenForTenant(): void
    {
        $client = $this->createAuthenticatedTenantClient();
        $event = $this->createEvent(
            EventKind::fromInt(EventKind::TEXT_NOTE),
            $this->tenantPubkey,
            content: str_repeat('a', 65537),
        );

        $rejection = $this->policy->allowEventSubmission($client, $event);

        $this->assertNotNull($rejection);
        $this->assertStringContainsString('event too large', $rejection->toWireReason());
    }

    public function testGuestCanSubmitAllowedKindTaggedToTenant(): void
    {
        $client = $this->createGuestClient();
        $tags = new TagCollection([Tag::pubkey($this->tenantPubkey)]);
        $event = $this->createEvent(EventKind::fromInt(EventKind::REACTION), $this->guestPubkey, $tags);

        $this->assertNull($this->policy->allowEventSubmission($client, $event));
    }

    public function testGuestCannotSubmitDisallowedKind(): void
    {
        $client = $this->createGuestClient();
        $event = $this->createEvent(EventKind::fromInt(EventKind::METADATA), $this->guestPubkey);

        $rejection = $this->policy->allowEventSubmission($client, $event);

        $this->assertNotNull($rejection);
        $this->assertTrue($rejection->isAuthRequired());
    }

    public function testGuestWriteRejectedWithoutTenantTag(): void
    {
        $client = $this->createGuestClient();
        $event = $this->createEvent(EventKind::fromInt(EventKind::REACTION), $this->guestPubkey);

        $rejection = $this->policy->allowEventSubmission($client, $event);

        $this->assertNotNull($rejection);
        $this->assertTrue($rejection->isAuthRequired());
    }

    public function testGuestWriteCarryingTheRequiredTagPrefixIsAdmitted(): void
    {
        $this->requireSiteTagPrefix();
        $client = $this->createGuestClient();
        $event = $this->siteComment(self::SITE.'john/3/16');

        $this->assertNull($this->policy->allowEventSubmission($client, $event));
    }

    public function testGuestWriteWithoutTheRequiredTagIsRejected(): void
    {
        $this->requireSiteTagPrefix();
        $client = $this->createGuestClient();
        $event = $this->createEvent(EventKind::fromInt(EventKind::COMMENT), $this->guestPubkey);

        $rejection = $this->policy->allowEventSubmission($client, $event);

        $this->assertNotNull($rejection);
        $this->assertTrue($rejection->isAuthRequired());
    }

    public function testGuestWriteNamingAnotherSiteIsRejected(): void
    {
        $this->requireSiteTagPrefix();
        $client = $this->createGuestClient();
        $event = $this->siteComment('https://example.com/john/3/16');

        $rejection = $this->policy->allowEventSubmission($client, $event);

        $this->assertNotNull($rejection);
        $this->assertTrue($rejection->isAuthRequired());
    }

    public function testAReplyCarryingOnlyTheRootScopeTagIsAdmitted(): void
    {
        $this->requireSiteTagPrefix();
        $client = $this->createGuestClient();
        $event = $this->createEvent(
            EventKind::fromInt(EventKind::COMMENT),
            $this->guestPubkey,
            new TagCollection([
                Tag::create('I', self::SITE.'john/3/16'),
                Tag::create('K', 'web'),
                Tag::create('e', str_repeat('ab', 32)),
                Tag::create('k', (string) EventKind::COMMENT),
            ]),
        );

        $this->assertNull(
            $this->policy->allowEventSubmission($client, $event),
            'A NIP-22 reply parents on the comment above it, so it carries no lowercase i. '
            .'Requiring the lowercase tag would admit top-level comments and refuse every reply.',
        );
    }

    public function testANoteNamingThePageInTheLowercaseTagIsAdmitted(): void
    {
        $this->requireSiteTagPrefix();
        $client = $this->createGuestClient();
        $event = $this->createEvent(
            EventKind::fromInt(EventKind::TEXT_NOTE),
            $this->guestPubkey,
            new TagCollection([Tag::create('i', self::SITE.'john/3/16'), Tag::create('k', 'web')]),
        );

        $this->assertNull(
            $this->policy->allowEventSubmission($client, $event),
            'A NIP-73 reference carries the lowercase tag and no uppercase root, so requiring both would admit neither.',
        );
    }

    public function testEitherProvenanceClauseAdmitsWhenBothAreConfigured(): void
    {
        $this->requireSiteTagPrefix(taggedToTenant: true);
        $client = $this->createGuestClient();

        $comment = $this->siteComment(self::SITE.'john/3/16');
        $giftWrap = $this->createEvent(
            EventKind::fromInt(EventKind::GIFT_WRAP),
            $this->guestPubkey,
            new TagCollection([Tag::pubkey($this->tenantPubkey)]),
        );

        $this->assertNull(
            $this->policy->allowEventSubmission($client, $comment),
            'A comment names a page and tags no tenant.',
        );
        $this->assertNull(
            $this->policy->allowEventSubmission($client, $giftWrap),
            'A gift wrap tags a tenant and names no page. One relay must be able to take both.',
        );
    }

    public function testAnEventMeetingNeitherProvenanceClauseIsRejected(): void
    {
        $this->requireSiteTagPrefix(taggedToTenant: true);
        $client = $this->createGuestClient();
        $event = $this->createEvent(EventKind::fromInt(EventKind::COMMENT), $this->guestPubkey);

        $rejection = $this->policy->allowEventSubmission($client, $event);

        $this->assertNotNull($rejection);
        $this->assertTrue($rejection->isAuthRequired());
    }

    public function testTenantIsNotSubjectToTheTagPrefixRequirement(): void
    {
        $this->requireSiteTagPrefix();
        $client = $this->createAuthenticatedTenantClient();
        $event = $this->createEvent(EventKind::fromInt(EventKind::COMMENT), $this->tenantPubkey);

        $this->assertNull($this->policy->allowEventSubmission($client, $event));
    }

    public function testBlacklistedNonTenantPubkeyIsRejected(): void
    {
        $this->policyManagement->banPubkey($this->guestPubkey);
        $client = $this->createAuthenticatedTenantClient();
        $event = $this->createEvent(EventKind::fromInt(EventKind::TEXT_NOTE), $this->guestPubkey);

        $rejection = $this->policy->allowEventSubmission($client, $event);

        $this->assertNotNull($rejection);
        $this->assertStringContainsString('content filter', $rejection->toWireReason());
    }

    public function testBlacklistedWordRejected(): void
    {
        $this->policyManagement->banWord(BlacklistWord::fromString('spam'));
        $client = $this->createGuestClient();
        $tags = new TagCollection([Tag::pubkey($this->tenantPubkey)]);
        $event = $this->createEvent(EventKind::fromInt(EventKind::TEXT_NOTE), $this->guestPubkey, $tags, 'Buy my spam product');

        $rejection = $this->policy->allowEventSubmission($client, $event);

        $this->assertNotNull($rejection);
        $this->assertStringContainsString('blocked', $rejection->toWireReason());
    }

    // Deliberate: the blacklist binds a tenant's own event, not only the events it relays — see ADR-0031
    public function testTenantIsRefusedItsOwnEventCarryingABannedWord(): void
    {
        $this->policyManagement->banWord(BlacklistWord::fromString('spam'));
        $client = $this->createAuthenticatedTenantClient();
        $event = $this->createEvent(EventKind::fromInt(EventKind::TEXT_NOTE), $this->tenantPubkey, null, 'my own spam');

        $rejection = $this->policy->allowEventSubmission($client, $event);

        $this->assertNotNull($rejection);
        $this->assertSame('blocked: event rejected by content filter', $rejection->toWireReason());
    }

    public function testTenantIsRefusedItsOwnEventCarryingABannedHashtag(): void
    {
        $this->policyManagement->banHashtag(Hashtag::fromString('nsfw'));
        $client = $this->createAuthenticatedTenantClient();
        $tags = new TagCollection([Tag::hashtag(Hashtag::fromString('nsfw'))]);
        $event = $this->createEvent(EventKind::fromInt(EventKind::TEXT_NOTE), $this->tenantPubkey, $tags);

        $rejection = $this->policy->allowEventSubmission($client, $event);

        $this->assertNotNull($rejection);
        $this->assertSame('blocked: event rejected by content filter', $rejection->toWireReason());
    }

    public function testAProtectedEventFromAnUnauthenticatedConnectionIsAnsweredAuthRequired(): void
    {
        $client = $this->createGuestClient();
        $event = $this->createEvent(EventKind::fromInt(EventKind::TEXT_NOTE), $this->guestPubkey, $this->protectedTags());

        $rejection = $this->policy->allowEventSubmission($client, $event);

        $this->assertSame('auth-required: this event may only be published by its author', $rejection?->toWireReason());
    }

    // Deliberate: the tenant bypass does not admit a protected event the tenant did not author — see ADR-0032
    public function testATenantRelayingSomeoneElsesProtectedEventIsBlocked(): void
    {
        $client = $this->createAuthenticatedTenantClient();
        $event = $this->createEvent(EventKind::fromInt(EventKind::TEXT_NOTE), $this->guestPubkey, $this->protectedTags());

        $rejection = $this->policy->allowEventSubmission($client, $event);

        $this->assertSame('blocked: this event may only be published by its author', $rejection?->toWireReason());
    }

    public function testATenantsProtectedEventRelayedByAGuestIsAnsweredAuthRequired(): void
    {
        $client = $this->createGuestClient();
        $event = $this->createEvent(EventKind::fromInt(EventKind::TEXT_NOTE), $this->tenantPubkey, $this->protectedTags());

        $rejection = $this->policy->allowEventSubmission($client, $event);

        $this->assertSame('auth-required: this event may only be published by its author', $rejection?->toWireReason());
    }

    public function testAProtectedEventFromItsAuthenticatedAuthorIsAdmitted(): void
    {
        $client = $this->createAuthenticatedTenantClient();
        $event = $this->createEvent(EventKind::fromInt(EventKind::TEXT_NOTE), $this->tenantPubkey, $this->protectedTags());

        $this->assertNull($this->policy->allowEventSubmission($client, $event));
    }

    public function testForgedZapReceiptIsRejectedAsInvalidEvenForTenant(): void
    {
        $client = $this->createAuthenticatedTenantClient();
        $event = $this->createEvent(
            EventKind::fromInt(EventKind::ZAP_RECEIPT),
            $this->guestPubkey,
            $this->zapReceiptTags($this->guestPubkey, withBolt11: false),
            content: '',
        );

        $rejection = $this->policy->allowEventSubmission($client, $event);

        $this->assertNotNull($rejection);
        $this->assertStringStartsWith('invalid: ', $rejection->toWireReason());
    }

    public function testZapReceiptWithoutRecipientIsRejectedAsInvalid(): void
    {
        $client = $this->createAuthenticatedTenantClient();
        $event = $this->createEvent(
            EventKind::fromInt(EventKind::ZAP_RECEIPT),
            $this->guestPubkey,
            $this->zapReceiptTags(recipient: null, withBolt11: true),
            content: '',
        );

        $rejection = $this->policy->allowEventSubmission($client, $event);

        $this->assertNotNull($rejection);
        $this->assertStringContainsString('NIP-57', $rejection->toWireReason());
    }

    public function testValidZapReceiptIsAccepted(): void
    {
        $client = $this->createAuthenticatedTenantClient();
        $event = $this->createEvent(
            EventKind::fromInt(EventKind::ZAP_RECEIPT),
            $this->guestPubkey,
            $this->zapReceiptTags($this->guestPubkey, withBolt11: true),
            content: '',
        );

        $this->assertNull($this->policy->allowEventSubmission($client, $event));
    }

    public function testForgedZapReceiptIngestRespondsOkFalseInvalidAndIsNotStored(): void
    {
        $event = SignedEventFactory::signedEvent($this->keyPair,
            EventKind::fromInt(EventKind::ZAP_RECEIPT),
            '',
            $this->zapReceiptTags($this->guestPubkey, withBolt11: false),
        );
        $connection = new CapturingConnection();

        $messages = $this->createSubmissionUseCase()->execute($this->createPipelineClient($connection), $event);
        EventLoop::run();

        $ok = $this->capturedOkMessage($messages);
        $this->assertSame($event->getId()->toHex(), $ok[1]);
        $this->assertFalse($ok[2]);
        self::assertIsString($ok[3]);
        $this->assertStringStartsWith('invalid:', $ok[3]);
        $this->assertSame(0, $this->storedEventCount($event));
    }

    public function testValidZapReceiptIngestRespondsOkTrueAndIsStored(): void
    {
        $this->policyManagement->addTenant($this->keyPair->getPublicKey());
        $event = SignedEventFactory::signedEvent($this->keyPair,
            EventKind::fromInt(EventKind::ZAP_RECEIPT),
            '',
            $this->zapReceiptTags($this->guestPubkey, withBolt11: true),
        );
        $connection = new CapturingConnection();

        $messages = $this->createSubmissionUseCase()->execute($this->createPipelineClient($connection), $event);
        EventLoop::run();

        $ok = $this->capturedOkMessage($messages);
        $this->assertSame($event->getId()->toHex(), $ok[1]);
        $this->assertTrue($ok[2]);
        $this->assertSame(1, $this->storedEventCount($event));
    }

    public function testTenantSubscriptionAllowed(): void
    {
        $client = $this->createAuthenticatedTenantClient();
        $filters = new FilterCollection([Filter::tryFromArray(['kinds' => [4]])]);

        $this->assertNull($this->policy->allowSubscription($client, $filters, 0));
    }

    public function testGuestNonReadableKindNarrowsToEmptyAndIsBeyondScope(): void
    {
        $client = $this->createGuestClient();
        $filters = new FilterCollection([Filter::tryFromArray(['kinds' => [4]])]);

        $scoped = $this->policy->filterForClient($client, $filters);

        $this->assertTrue($scoped->isBeyondScope());
        $this->assertSame([], $scoped->getFilters()->toArray()[0]->getKinds()?->toInts());
    }

    public function testGuestNonTenantAuthorNarrowsToEmptyAndIsBeyondScope(): void
    {
        $client = $this->createGuestClient();
        $filters = new FilterCollection([Filter::tryFromArray(['authors' => [$this->guestPubkey->toHex()]])]);

        $scoped = $this->policy->filterForClient($client, $filters);

        $this->assertTrue($scoped->isBeyondScope());
        $this->assertSame([], self::authorHexes($scoped->getFilters()->toArray()[0]));
    }

    public function testFilterForGuestDefaultsBareFilterToTenantsWithoutBeyondScope(): void
    {
        $client = $this->createGuestClient();
        $filters = new FilterCollection([Filter::tryFromArray([])]);

        $constrained = $this->policy->filterForClient($client, $filters);

        $this->assertSame([$this->tenantPubkey->toHex()], self::authorHexes($constrained->getFilters()->toArray()[0]));
        $this->assertFalse($constrained->isBeyondScope());
    }

    public function testFilterForTenantIsNotScopedToTenantAuthors(): void
    {
        $client = $this->createAuthenticatedTenantClient();
        $filters = new FilterCollection([Filter::tryFromArray([])]);

        $result = $this->policy->filterForClient($client, $filters);

        $this->assertFalse($result->getFilters()->toArray()[0]->hasAuthors());
        $this->assertFalse($result->isBeyondScope());
    }

    public function testATenantsFilterIsStillBoundedByTheConfiguredCeiling(): void
    {
        $client = $this->createAuthenticatedTenantClient();

        $result = $this->policy->filterForClient($client, new FilterCollection([Filter::tryFromArray([])]));

        $this->assertSame(RelayLimits::defaults()->getMaxLimit(), $result->getFilters()->toArray()[0]->getLimit());
    }

    public function testAllowsAuthenticationForTenantPubkey(): void
    {
        $this->assertNull($this->policy->allowsAuthentication($this->tenantPubkey));
    }

    public function testRejectsAuthenticationForNonTenantPubkey(): void
    {
        $this->assertNotNull($this->policy->allowsAuthentication($this->guestPubkey));
    }

    public function testCanClientReceiveEventTenantSeesAll(): void
    {
        $client = $this->createAuthenticatedTenantClient();
        $event = $this->createEvent(EventKind::fromInt(4), $this->guestPubkey);

        $this->assertTrue($this->policy->canClientReceiveEvent($client, $event));
    }

    public function testCanClientReceiveEventGuestSeesOnlyTenantEvents(): void
    {
        $client = $this->createGuestClient();

        $tenantEvent = $this->createEvent(EventKind::fromInt(EventKind::TEXT_NOTE), $this->tenantPubkey);
        $guestEvent = $this->createEvent(EventKind::fromInt(EventKind::TEXT_NOTE), $this->guestPubkey);

        $this->assertTrue($this->policy->canClientReceiveEvent($client, $tenantEvent));
        $this->assertFalse($this->policy->canClientReceiveEvent($client, $guestEvent));
    }

    public function testCanClientReceiveEventGuestCannotSeeNonReadableKind(): void
    {
        $client = $this->createGuestClient();
        $event = $this->createEvent(EventKind::fromInt(4), $this->tenantPubkey);

        $this->assertFalse($this->policy->canClientReceiveEvent($client, $event));
    }

    public function testCanClientReceiveEventGuestSeesGlobalKindFromNonTenant(): void
    {
        $client = $this->createGuestClient();
        $event = $this->createEvent(EventKind::fromInt(EventKind::NOSTR_CONNECT), $this->guestPubkey);

        $this->assertTrue($this->policy->canClientReceiveEvent($client, $event));
    }

    public function testGuestCannotReceiveGiftWrapEvenWhenTenantAuthored(): void
    {
        $client = $this->createGuestClient();
        $event = $this->createEvent(EventKind::fromInt(EventKind::GIFT_WRAP), $this->tenantPubkey);

        $this->assertFalse($this->policy->canClientReceiveEvent($client, $event));
    }

    public function testAuthenticatedTenantCanReceiveGiftWrap(): void
    {
        $client = $this->createAuthenticatedTenantClient();
        $event = $this->createEvent(EventKind::fromInt(EventKind::GIFT_WRAP), $this->guestPubkey);

        $this->assertTrue($this->policy->canClientReceiveEvent($client, $event));
    }

    public function testGuestGiftWrapSubscriptionIsBeyondScopeAndNarrowsToEmpty(): void
    {
        $client = $this->createGuestClient();
        $filters = new FilterCollection([Filter::tryFromArray([
            'kinds' => [EventKind::GIFT_WRAP],
            '#p' => [$this->tenantPubkey->toHex()],
        ])]);

        $scoped = $this->policy->filterForClient($client, $filters);

        $this->assertTrue($scoped->isBeyondScope());
        $this->assertSame([], $scoped->getFilters()->toArray()[0]->getKinds()?->toInts());
    }

    public function testFilterForGuestGlobalKindSubscriptionIsNotAuthorConstrained(): void
    {
        $client = $this->createGuestClient();
        $filters = new FilterCollection([Filter::tryFromArray([
            'kinds' => [EventKind::NOSTR_CONNECT],
            '#p' => [$this->tenantPubkey->toHex()],
        ])]);

        $scoped = $this->policy->filterForClient($client, $filters);

        $this->assertFalse($scoped->isBeyondScope());
        $this->assertFalse($scoped->getFilters()->toArray()[0]->hasAuthors());
    }

    public function testAuthenticatedTenantIsRateLimitExempt(): void
    {
        $client = $this->createAuthenticatedTenantClient();

        $this->assertTrue($this->policy->isRateLimitExempt($client));
    }

    public function testGuestIsNotRateLimitExempt(): void
    {
        $client = $this->createGuestClient();

        $this->assertFalse($this->policy->isRateLimitExempt($client));
    }

    public function testClientAuthenticatedAsNonTenantIsNotRateLimitExempt(): void
    {
        $clientId = ClientId::fromBytes(random_bytes(16));
        $client = $this->createRelayClient($clientId);
        $this->authManager->authenticate($clientId, $this->guestPubkey);

        $this->assertFalse($this->policy->isRateLimitExempt($client));
    }

    public function testPolicyReflectsRuntimeGuestPolicyChange(): void
    {
        $client = $this->createGuestClient();
        $event = $this->createEvent(EventKind::fromInt(4), $this->tenantPubkey);

        $this->assertFalse($this->policy->canClientReceiveEvent($client, $event));

        $this->policyManagement->setGuestPolicy(new GuestPolicy(
            new GuestReadPolicy(EventKindCollection::fromInts([4]), new EventKindCollection(), true),
            new GuestWritePolicy(new EventKindCollection(), false),
        ));

        $this->assertTrue($this->policy->canClientReceiveEvent($client, $event));
    }

    public function testStoredAndLiveReadPathsAgreeWhenOnlyGlobalKindsAreReadable(): void
    {
        $this->policyManagement->setGuestPolicy(new GuestPolicy(
            new GuestReadPolicy(new EventKindCollection(), EventKindCollection::fromInts([EventKind::NOSTR_CONNECT]), false),
            GuestWritePolicy::defaults(),
        ));
        $client = $this->createGuestClient();
        $giftWrap = $this->createEvent(EventKind::fromInt(EventKind::GIFT_WRAP), $this->guestPubkey);

        $scoped = $this->policy->filterForClient(
            $client,
            new FilterCollection([Filter::tryFromArray(['kinds' => [EventKind::GIFT_WRAP]])]),
        );

        $this->assertFalse($this->policy->canClientReceiveEvent($client, $giftWrap));
        $this->assertTrue($scoped->isBeyondScope());
        $this->assertSame([], $scoped->getFilters()->toArray()[0]->getKinds()?->toInts());
    }

    public function testGuestsReadNothingWhenNoKindIsReadable(): void
    {
        $this->policyManagement->setGuestPolicy(new GuestPolicy(
            new GuestReadPolicy(new EventKindCollection(), new EventKindCollection(), false),
            GuestWritePolicy::defaults(),
        ));
        $client = $this->createGuestClient();
        $note = $this->createEvent(EventKind::fromInt(EventKind::TEXT_NOTE), $this->tenantPubkey);

        $scoped = $this->policy->filterForClient($client, new FilterCollection([Filter::tryFromArray([])]));

        $this->assertFalse($this->policy->canClientReceiveEvent($client, $note));
        $this->assertSame([], $scoped->getFilters()->toArray()[0]->getKinds()?->toInts());
    }

    private function requireSiteTagPrefix(bool $taggedToTenant = false): void
    {
        $this->policyManagement->setGuestPolicy(new GuestPolicy(
            GuestReadPolicy::defaults(),
            new GuestWritePolicy(
                EventKindCollection::fromInts([EventKind::COMMENT, EventKind::TEXT_NOTE, EventKind::GIFT_WRAP]),
                $taggedToTenant,
                new TagPrefixRequirementCollection([
                    new TagPrefixRequirement(TagType::rootExternalContent(), [self::SITE]),
                    new TagPrefixRequirement(TagType::externalContent(), [self::SITE]),
                ]),
            ),
        ));
    }

    private function siteComment(string $url): Event
    {
        return $this->createEvent(
            EventKind::fromInt(EventKind::COMMENT),
            $this->guestPubkey,
            new TagCollection([Tag::create('I', $url)]),
        );
    }

    private function protectedTags(): TagCollection
    {
        return new TagCollection([Tag::create(TagType::PROTECTED)]);
    }

    private function createAuthenticatedTenantClient(): RelayClient
    {
        $clientId = ClientId::fromBytes(random_bytes(16));
        $client = $this->createRelayClient($clientId);
        $this->authManager->authenticate($clientId, $this->tenantPubkey);

        return $client;
    }

    private function createGuestClient(): RelayClient
    {
        return $this->createRelayClient(ClientId::fromBytes(random_bytes(16)));
    }

    private function createRelayClient(ClientId $clientId): RelayClient
    {
        return new RelayClient(
            $clientId,
            new ConnectionInfo(IpAddress::fromString('127.0.0.1'), 'test-agent', Timestamp::now()),
        );
    }

    private function createEvent(
        EventKind $kind,
        PublicKey $pubkey,
        ?TagCollection $tags = null,
        string $content = 'test content',
    ): Event {
        return SignedEventFactory::fromRumour(new Rumour(
            $pubkey,
            Timestamp::now(),
            $kind,
            $tags ?? new TagCollection(),
            EventContent::fromString($content)
        ));
    }

    private function zapReceiptTags(?PublicKey $recipient = null, bool $withBolt11 = true): TagCollection
    {
        $senderHex = $this->keyPair->getPublicKey()->toHex();
        $zapRequestJson = json_encode([
            'pubkey' => $senderHex,
            'content' => '',
            'tags' => [['amount', '21000']],
        ], JSON_THROW_ON_ERROR);

        $tags = [
            Tag::tryFromArray(['P', $senderHex]),
            Tag::tryFromArray(['description', $zapRequestJson]),
        ];
        if (null !== $recipient) {
            $tags[] = Tag::pubkey($recipient);
        }
        if ($withBolt11) {
            $tags[] = Tag::tryFromArray(['bolt11', 'lnbc210n1pexample']);
        }

        return new TagCollection($tags);
    }

    private function createSubmissionUseCase(): ProcessEventSubmissionUseCase
    {
        $eventStore = $this->directEventStore();
        $clientMessenger = new ClientMessenger($this->clientManager);

        $distributor = new EventDistributor(
            $this->policy,
            $this->subscriptionManager,
            $this->clientManager,
            $clientMessenger,
            new NullLogger(),
        );

        $acceptedPublisher = new AcceptedEventPublisher(
            $this->clientManager,
            $distributor,
            new AmphpDeferredExecutor(),
        );

        $pipeline = new AcceptedEventPipeline(
            $eventStore,
            $acceptedPublisher,
            new EventDeletionProcessor($eventStore, new NullLogger()),
            new NullLogger(),
        );

        $rateLimiter = $this->createStub(RateLimiterInterface::class);
        $rateLimiter->method('tryConsume')->willReturn(true);

        return new ProcessEventSubmissionUseCase(
            new EventAdmission(
                $this->policy,
                new RateLimitGate($rateLimiter, $this->policy),
                new EventValidator(SignedEventFactory::signer(), new NipComplianceValidator(SignedEventFactory::signer())),
                new SystemClock(),
            ),
            $pipeline,
            new AuthChallengeIssuer($this->authManager),
            $this->clientManager,
            new NullLogger(),
        );
    }

    private function directEventStore(): RelayEventStoreInterface
    {
        return new class(WriteContext::forConnection($this->pdo)->getEventWriteStore()) implements RelayEventStoreInterface {
            public function __construct(private readonly EventWriteStore $writeStore)
            {
            }

            public function store(Event $event): EventStoreOutcome
            {
                return $this->writeStore->store($event);
            }

            public function findByFilters(FilterCollection $filters, int $limit = 100): EventCollection
            {
                return new EventCollection([]);
            }

            public function countByFilters(FilterCollection $filters): EventCount
            {
                return EventCount::exact(0);
            }

            public function deleteByEventIds(EventIdCollection $eventIds, PublicKey $author): int
            {
                return 0;
            }

            public function deleteByCoordinates(EventCoordinateCollection $coordinates, PublicKey $author): int
            {
                return 0;
            }
        };
    }

    private function createPipelineClient(CapturingConnection $connection): RelayClient
    {
        return $this->clientManager->registerClient(
            $connection,
            new ConnectionInfo(IpAddress::fromString('127.0.0.1'), 'test-agent', Timestamp::now()),
        );
    }

    /**
     * @param list<RelayMessage> $messages
     *
     * @return list<mixed>
     */
    private function capturedOkMessage(array $messages): array
    {
        foreach ($messages as $message) {
            if ($message instanceof OkMessage) {
                return $message->toArray();
            }
        }

        $this->fail('No OK message captured');
    }

    /**
     * @param list<RelayMessage> $messages
     */
    private function returnedAuthChallenge(array $messages): bool
    {
        return array_any($messages, static fn (RelayMessage $message): bool => $message instanceof AuthMessage);
    }

    private function storedEventCount(Event $event): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM events WHERE event_id = ?');
        $stmt->execute([hex2bin($event->getId()->toHex())]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * @return list<string>
     */
    private static function authorHexes(Filter $filter): array
    {
        return $filter->getAuthors()?->toHexes() ?? [];
    }
}
