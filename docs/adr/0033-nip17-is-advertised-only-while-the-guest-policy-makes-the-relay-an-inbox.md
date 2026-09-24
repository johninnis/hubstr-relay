# 33. NIP-17 is advertised only while the guest policy makes the relay an inbox

## Status

Accepted

## Context

NIP-17 describes how a relay serves as a direct-message inbox: it accepts kind 1059 gift wraps addressed to its users, serves them only to the recipient behind AUTH, and advertises 17 in its relay-information document so a sender's client, reading the recipient's kind 10050 list, can tell which relays will take the message.

This relay does the first two by design. The default guest write policy admits kind 1059 when it `p`-tags a tenant, and the default guest read policy keeps 1059 out of the readable kinds, so a guest can deliver a wrap and never read one ([ADR-0017](0017-gift-wraps-are-excluded-from-the-guest-readable-kinds.md)). The recipient tenant reads its mailbox at full scope over NIP-42.

What makes advertising it awkward is that every part of that behaviour is runtime configuration. `setguestpolicy` can remove 1059 from the writable kinds, turn off `tagged_to_tenant`, or add 1059 to the readable or global kinds, and after any of those the relay is no longer an inbox in NIP-17's sense. The `supported_nips` list, though, is assembled once from the config file. A static 17 would be true on the day the relay started and a lie the moment an operator changed the policy, and a lie in that field sends direct messages to a relay that will expose or refuse them.

The relay-information document is already built on every request: the name, description and icon overrides are read from the live policy projection each time ([ADR-0006](0006-policystate-is-a-mutable-in-process-read-model.md)). The supported list can be read the same way.

## Decision

`GuestPolicy::servesAsDirectMessageInbox()` states the condition as a pure predicate on the policy: 1059 is a writable kind, `tagged_to_tenant` is on, and 1059 is in neither the readable nor the global kinds. `Nip11InfoProvider` appends 17 to the configured supported list only while that predicate holds, so the document says 17 exactly when the relay behaves as an inbox.

The other advertised NIPs stay a static list in the configuration, because nothing at runtime changes whether the relay implements them.

## Consequences

- A sender's client sees 17 on this relay only when a wrap it sends will be accepted and kept private. Changing the guest policy so that this stops being true removes the advertisement in the same request.
- The predicate is the one definition of "this relay is an inbox". Do not add a config key or a second flag for it; the guest policy already carries every fact it needs, and a separate switch could disagree with it.
- Do not make 17 static "because it is the default". The default is the common case, not the only one, and the field is read by clients deciding where to send private mail.
- Do not move the other NIPs into the same per-request computation for uniformity. They do not vary, and reading them from the live policy would suggest that they might.
