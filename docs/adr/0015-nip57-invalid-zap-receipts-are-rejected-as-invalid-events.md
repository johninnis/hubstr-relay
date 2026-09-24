# 15. A NIP-57-invalid zap receipt is refused as invalid, ahead of the tenant bypass

## Status

Accepted

## Context

`HubstrPolicy::allowEventSubmission` answers one question for every inbound event: may this client write this event here. Almost every answer is about the client — a guest writing a kind the guest policy does not allow, an author on the blacklist, an event too large. Those are refusals of a well-formed event, and the NIP-01 word for them is `blocked:`.

A kind-9735 zap receipt is different. The receipt is a claim that someone was paid, made by whoever relays it rather than by the payer, and the stats built on it (totals, the most-zapped leaderboards) are only worth anything if the claim is internally consistent: a parseable bolt11 amount that matches the request, and a named recipient. A receipt that fails those checks is not a well-formed event the relay is declining to accept. It is malformed, and telling the client `blocked:` would invite it to retry the same bytes elsewhere, or to a human reader suggest the relay simply does not want zaps.

Two things about the shape of this check read like mistakes and are not.

The first is that it is here at all, in a method whose other answers are policy. NIP-57 validity is a protocol property, and the tidy home for a protocol property is an event validator. This relay has no seam for one: nostr-relay's `RelayServerFactory` constructs the event validator it hands to admission, so a host cannot supply its own. Given that, the choice is between the policy hook the host does own and no check at all.

The second is that it runs before the tenant bypass. The size limit and the content blacklist do too, for the reasons recorded in [ADR-0031](0031-the-size-limit-and-the-content-blacklist-are-checked-ahead-of-the-tenant-bypass.md), but those are limits on the event; this one is a judgement about its validity, and it overrules a tenant for a different reason. A tenant is trusted to write what it likes to its own relay, and this check has to hold regardless: the stats do not care who relayed a forged receipt, and a tenant's own client can be as buggy as anyone's.

## Decision

A kind-9735 event whose `ZapReceipt::tryFromEvent` returns nothing, or which names no recipient, is refused with `PolicyRejection::invalid(...)`, so the client receives `OK false "invalid: zap receipt failed NIP-57 validation …"`. The check precedes the tenant bypass and applies to every author.

The relay verifies only the receipt's internal consistency. It does not check that the receipt was signed by the recipient's payment provider, which would need the recipient's profile and a network fetch on the admission path.

## Consequences

- A malformed receipt never reaches storage, so the zap statistics are built only on receipts that parse and name someone.
- The refusal is a returned value like every other answer this method gives. Do not change it to `blocked:` to make the method uniform: the prefix is the part a client acts on, and these two refusals mean different things.
- Do not move the check below the tenant bypass to make the method read consistently. The ordering is the decision.
- A guest can still publish a *consistent but untrue* receipt, because provider-signature verification is out of scope here. The zap statistics are therefore a measure of what was claimed, not of what was paid, and are shown only to tenants.
- If nostr-relay later lets a host supply its own `EventValidatorInterface`, this check moves there and `allowEventSubmission` goes back to answering only policy questions.
