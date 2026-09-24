# 29. The `p` tags of a follow or mute list are not indexed

## Status

Accepted

## Context

Every tag on a stored event goes into `event_tags`, which is what a `#`-prefixed filter is resolved against, with one exception: the `p` tags of a kind 3 follow list and a kind 10000 mute list are skipped.

Those two kinds are different in shape from everything else. An ordinary event carries a handful of `p` tags naming who it mentions; a follow list carries one per contact, and a person following a thousand accounts produces a thousand tag rows for a single event. The lists are replaceable, so there is only ever one per author, but the relay caches many authors' lists. Measured on a corpus of two million events, indexing them would add 4.76 million rows to a tag index that currently holds 5.7 million: an eighty-three per cent increase in the index, for two kinds out of every kind the relay stores.

The data is not lost. The denormaliser writes both lists into `profile_follows` and `profile_mutes`, which is what the explore and statistics queries read, and it is why the tags were skipped in the first place.

The cost of the skip is a protocol one, and it is real. `{"kinds":[3],"#p":["<pubkey>"]}` is the standard question "who follows me", it is a legal NIP-01 filter, and this relay answers it with nothing while holding the events that satisfy it. The client is not told; it simply receives an empty result and an EOSE.

## Decision

The `p` tags of kind 3 and kind 10000 events are not written to `event_tags`, and a `#p` filter on those kinds therefore matches nothing. The events themselves are stored whole and are returned by any filter that does not depend on that tag index, including `{"kinds":[3],"authors":[...]}`, which is how a client fetches a known author's follow list.

The relationship remains queryable through the management API, which reads the denormalised tables.

## Consequences

- The tag index stays roughly half the size it would otherwise be, on the two kinds that would have dominated it.
- A client asking who follows a given pubkey gets an empty answer rather than a wrong one, but it gets no indication that the question was one this relay cannot answer. That is the part of this decision that is hard to defend, and it is the reason the decision is written down rather than left in the code.
- Fetching a named author's follow list works normally, which is the common client need.
- Do not remove the skip without measuring the index growth again on a corpus of the size the relay actually holds. The number above is what the decision rests on.
- If the skip is ever removed, the denormalised tables stay: they answer aggregate questions the tag index cannot.
