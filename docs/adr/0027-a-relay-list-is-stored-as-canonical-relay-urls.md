# 27. A relay list is stored as canonical relay URLs

## Status

Accepted

## Context

A kind 10002 relay list is denormalised into `profile_relays` so the explore and stats queries can count and join on it. Each `r` tag carries a relay URL and an optional marker of `read` or `write`, absent meaning both.

Reading those tags by position — value 0 is the URL, value 1 is the marker — worked, and stored whatever string the author wrote. That meant `wss://Relay.Example.com/` and `wss://relay.example.com` were two rows under a primary key of `(pubkey, relay_url)`, the same relay counted twice; a marker of anything other than `read` or `write` was coerced to `both` by a check written here; and a value that was not a relay URL at all was stored as though it were one.

nostr-core already reads `r` tags into typed relay references, with `RelayUrl` canonicalising the scheme, host case, default port and trailing punctuation, and it already answers the marker. The host was re-deriving, less well, what the library hands over.

## Decision

The denormaliser reads relay lists through nostr-core's `TagReferenceExtractor` and stores the canonical form of each `RelayUrl`. A tag whose value does not parse as a relay URL is not a relay entry and is not stored. The marker is a `RelayMarker`, and anything that is neither `read` nor `write` is `both`, which is what NIP-65 means by an `r` tag without a marker.

## Consequences

- One relay is one row per author however its author spelled it, so the relay counts in `getstats` and the explore results stop double-counting.
- An author who publishes a non-relay URL in an `r` tag has that entry dropped rather than counted. The event itself is stored untouched; only the derived table is opinionated.
- Rows written before this change keep whatever form they were stored in, because a relay list is replaced per author: a pubkey's rows become canonical the next time it publishes its list. The column therefore holds a mix until then, and a count over it is approximate in that window.
- Do not reintroduce a marker check here. The closed set lives in `RelayMarker`, and the schema's default matches it.
