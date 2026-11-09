# 47. A full replay guard refuses a new event rather than evicting a live one

## Status

Accepted

## Context

`InMemoryNip98ReplayGuard` records each NIP-98 event id for the replay window, and bounds its memory at a fixed number of entries. It evicted its oldest entry when full. Nothing limits who may fill it: the guard records an event once its signature verifies, before the tenant check, so any key can write to it. An attacker who sent enough validly signed events inside one replay window could evict a tenant's recorded event and then replay it.

The alternatives were to evict as before, which gives up replay protection exactly when the guard is under attack, or to leave it unbounded, which lets the same attacker exhaust memory.

## Decision

When every entry is still live, the guard refuses the new event: `recordOnce` returns `false`, which the validator reports as a replay. An expired entry is pruned first, so room returns as soon as the window passes.

## Consequences

- A recorded event stays refused for its whole window, however many events follow it.
- An attacker able to fill the guard can refuse new management requests until entries expire, for at most one replay window after the flood stops. Accepted: this costs availability, not authentication, and a refused tenant can sign again.
- Reaching the bound takes 100000 verified signatures inside one window by default.
- Do not evict a live entry to make room.
