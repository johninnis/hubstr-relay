# 31. The size limit and the content blacklist are checked ahead of the tenant bypass

## Status

Accepted

## Context

`HubstrPolicy::allowEventSubmission` answers one question for every inbound event: may this client write this event here. Most of its rules are about the client's standing. A guest may publish only a writable kind, and only with provenance; a tenant, or anyone relaying a tenant-authored event, is exempt from both, through an early return halfway down the method. A reader who finds that return will want to move it to the top, so that a tenant is exempt from everything: the tenant is the operator, and an operator should be able to write what they like to their own relay.

Several rules run before the return and therefore bind a tenant too. One is the NIP-57 receipt check, whose reason is recorded in [ADR-0015](0015-nip57-invalid-zap-receipts-are-rejected-as-invalid-events.md); another is the NIP-70 author check, recorded in [ADR-0032](0032-a-protected-event-is-published-only-by-its-authenticated-author.md). The two this record decides are limits on the event rather than judgements about it: the `max_content_length` ceiling, and the content blacklist of banned words, pubkeys and hashtags.

## Decision

A rule about the event binds every author, tenant included. The tenant bypass skips only the rules about the client's standing.

- **The size limit binds a tenant** because the cost of an oversized event is the relay's, whoever wrote it: it is held in memory, written to disk and fanned out to every subscriber, and a tenant's client can be as buggy as anyone's. The limit is also what the relay advertises as `max_content_length` in its NIP-11 document, and a relay that publishes one number and stores a larger event from its operator is lying about itself. This is the same reasoning that keeps the read ceiling on a tenant in [ADR-0004](0004-nip42-is-a-tenant-only-scope-lift-offer-not-a-connection-gate.md): authentication lifts scope, never volume.
- **The blacklist binds a tenant** because a ban is a statement about the relay's content, not about who is trusted. [ADR-0030](0030-a-content-ban-purges-through-the-filter-that-refused-the-write.md) makes a banned word mean that the relay holds nothing matching it, by refusing the write and purging what was stored through the same filter. The purge runs once, when the word is banned. If a tenant could write past the filter afterwards, that event would never meet the purge, and an operator who banned a word and searched for it would find it again. The blacklist is the operator's own rule, and it applies to the operator's own key; the API refuses to ban a tenant pubkey (`TenantPolicyFailure::ActiveTenant`), so the only way a tenant meets the filter is through content it chose to publish, and unbanning restores it.

Both refusals are `blocked:`, the same reason a guest receives. They are not `auth-required:`, because authenticating would change nothing.

## Consequences

- A tenant that publishes an oversized event, or one carrying a banned word or hashtag, is refused exactly as a guest would be, and the NIP-11 document stays true for every author.
- An operator who wants to publish a banned word unbans it first. There is no tenant exemption to reach for, and the blacklist never silently diverges from what the store holds.
- Do not move the tenant bypass above these checks so that the operator can post anything. The size limit protects the relay's own resources and the blacklist protects the invariant of [ADR-0030](0030-a-content-ban-purges-through-the-filter-that-refused-the-write.md); neither is a question of trust.
- Do not exempt a tenant from the blacklist because the tenant is trusted. Trust is what the bypass already grants for kinds and provenance; the blacklist is a content decision, and a content decision has to hold for all content.
- `HubstrPolicyTest` pins both refusals for an authenticated tenant.
