# 1. Wildcard CORS on the management API

## Status

Accepted

## Context

The management API is JSON-RPC over HTTP (NIP-86), authenticated with NIP-98: every RPC call carries an `Authorization` header containing a Nostr event that binds the request URL, method, and SHA-256 hash of the body. The signer must be a configured tenant pubkey, and the event timestamp must be within 60 seconds of the server clock.

The HTTP endpoint must be callable by any Nostr client — web, mobile, desktop, CLI. A wildcard `Access-Control-Allow-Origin: *` reads like a security smell, so the obvious-looking alternative is an origin allowlist.

## Decision

The endpoint responds with `Access-Control-Allow-Origin: *`.

An origin allowlist would block legitimate web-based admin clients without providing real protection: native clients ignore CORS entirely, and the NIP-98 signature requirement already prevents an unauthorised origin from issuing a valid request. The authority gate is the signature, not the origin.

## Consequences

- Any Nostr client can call the management API from any origin.
- An observed `Authorization` header cannot be replayed within the 60-second window: `Nip98Validator` is given a replay guard that records the id of each accepted auth event and refuses a second use of it. The residual exposure is therefore the key itself, not the header. The operator should serve the relay over TLS, and should prefer signing with a hardware/remote signer (NIP-46) over storing keys in browser localStorage.
- Do not "harden" this by adding an origin allowlist — it adds no security against the threat model and breaks legitimate clients.
