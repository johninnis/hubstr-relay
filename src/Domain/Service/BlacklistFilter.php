<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Domain\Service;

use Innis\Hubstr\Relay\Domain\Collection\BlacklistWordCollection;
use Innis\Hubstr\Relay\Domain\ValueObject\BlacklistWord;
use Innis\Nostr\Core\Domain\Collection\HashtagCollection;
use Innis\Nostr\Core\Domain\Collection\PublicKeyCollection;
use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use Innis\Nostr\Core\Domain\ValueObject\Tag\Hashtag;

final readonly class BlacklistFilter
{
    public function __construct(
        private BlacklistWordCollection $words = new BlacklistWordCollection(),
        private PublicKeyCollection $pubkeys = new PublicKeyCollection(),
        private HashtagCollection $hashtags = new HashtagCollection(),
    ) {
    }

    public function isBlacklisted(Event $event): bool
    {
        return $this->isPubkeyBlacklisted($event->getPubkey())
            || $this->containsBlacklistedWord((string) $event->getContent())
            || $this->containsBlacklistedHashtag($event);
    }

    public function isPubkeyBlacklisted(PublicKey $pubkey): bool
    {
        return $this->pubkeys->contains($pubkey);
    }

    public function isHashtagBlacklisted(Hashtag $hashtag): bool
    {
        return $this->hashtags->contains($hashtag);
    }

    // Deliberate: this substring test is the one definition of what a banned word matches, and the purge asks it too — see ADR-0030
    public function containsBlacklistedWord(string $content): bool
    {
        if ($this->words->isEmpty()) {
            return false;
        }

        $lowerContent = mb_strtolower($content);

        return array_any(
            $this->words->toStrings(),
            static fn (string $word): bool => str_contains($lowerContent, $word),
        );
    }

    public function withWord(BlacklistWord $word): self
    {
        if ($this->words->contains($word)) {
            return $this;
        }

        return new self($this->words->merge(new BlacklistWordCollection([$word])), $this->pubkeys, $this->hashtags);
    }

    public function withoutWord(BlacklistWord $word): self
    {
        return new self($this->words->diff(new BlacklistWordCollection([$word])), $this->pubkeys, $this->hashtags);
    }

    public function withPubkey(PublicKey $pubkey): self
    {
        if ($this->isPubkeyBlacklisted($pubkey)) {
            return $this;
        }

        return new self($this->words, $this->pubkeys->merge(new PublicKeyCollection([$pubkey])), $this->hashtags);
    }

    public function withoutPubkey(PublicKey $pubkey): self
    {
        return new self($this->words, $this->pubkeys->diff(new PublicKeyCollection([$pubkey])), $this->hashtags);
    }

    public function withHashtag(Hashtag $hashtag): self
    {
        if ($this->isHashtagBlacklisted($hashtag)) {
            return $this;
        }

        return new self($this->words, $this->pubkeys, $this->hashtags->merge(new HashtagCollection([$hashtag])));
    }

    public function withoutHashtag(Hashtag $hashtag): self
    {
        return new self($this->words, $this->pubkeys, $this->hashtags->diff(new HashtagCollection([$hashtag])));
    }

    public function getWords(): BlacklistWordCollection
    {
        return $this->words;
    }

    public function getPubkeys(): PublicKeyCollection
    {
        return $this->pubkeys;
    }

    public function getHashtags(): HashtagCollection
    {
        return $this->hashtags;
    }

    private function containsBlacklistedHashtag(Event $event): bool
    {
        if ($this->hashtags->isEmpty()) {
            return false;
        }

        return !$event->getTags()->getHashtags()->intersect($this->hashtags)->isEmpty();
    }
}
