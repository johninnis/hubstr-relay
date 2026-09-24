# 26. A block outlives the reason written with it

## Status

Accepted

## Context

`blockip` takes an address and a free-text reason, and the reason is untrusted input from a management client: it is stored, returned by `listblockedips`, and shown to whoever reads that list. Like the relay's other text fields it is capped, at 2,048 characters, so a client cannot write unbounded text into the database through an API call.

Where that cap is enforced decides what happens to a block that already exists. A cap applied on every construction of the value would also be applied when the relay rehydrates its read model from the database at startup, and then a row written before the cap existed, or by an operator with SQL access, would fail to load. The effect would be a block that quietly stops blocking — the one failure mode a block list must not have. The same is true of an address that no longer parses: rehydration has to decide what to do with a row it cannot represent, and the two cases pull in opposite directions.

## Decision

`BlockedIp` is built two ways, by trust, and the cap belongs to one of them.

- `tryFromParts()` parses untrusted input. It trims the reason and returns null beyond 2,048 characters, which the RPC handler turns into a 400 naming the limit. Nothing is stored.
- `fromParts()` takes values the relay already holds — a row being rehydrated, a test fixture — and asserts nothing about length. A reason already in the database loads whatever it says.

A stored address that no longer parses as an IP address is dropped on load rather than throwing, because such a row can match no connection: it is unenforceable either way, and a fault there would take the relay down over one bad row instead of leaving every other block in force.

## Consequences

- An over-long reason can never enter through the API, and a reason already in the database never costs the relay the block it belongs to.
- The cap counts characters, not bytes, because that is what the API documents; a reason of 2,048 accented characters is accepted.
- Do not "tidy" this into a single constructor that always caps. That is the change that turns a startup into a silently shorter block list.
- Do not make the dropped-address case throw to be strict about corruption. Strictness there costs availability and buys nothing, since the row protects nobody.
