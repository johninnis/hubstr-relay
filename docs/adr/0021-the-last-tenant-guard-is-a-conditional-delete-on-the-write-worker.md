# 21. The last-tenant guard is a conditional delete on the write worker

## Status

Accepted

## Context

A relay must always keep at least one tenant: tenants are the only keys that can call the management API, so removing the last one locks every operator out until the process restarts and re-seeds the configured admin.

The obvious home for that rule is the use case — read the tenant count from the in-memory policy state, refuse when it is one, otherwise remove. It is wrong there, for a reason the code would not show. Every policy mutation persists first and projects into memory second (ADR-0006), and persisting means awaiting a round trip to the write worker (ADR-0010). The await suspends the calling fibre, so between the count being read and the projection being updated, another management call can run. Two removals of two different tenants, issued together against a relay with two tenants, each read a count of two, each pass the check, and each commit. The relay ends with none.

No check made on the main process before the write can close that window, because the window *is* the write. Holding a lock across the await would serialise management calls behind one another and thread a concurrency primitive through the Application layer to protect a single rule.

The write worker is already the serialisation point. It is the only writer and applies one command at a time, so a statement that decides and deletes in one step cannot interleave with anything.

## Decision

`PolicyWriteStore::removeTenantUnlessLast()` deletes with the guard in the statement itself — the row goes only while more than one tenant exists — and returns the number of rows removed. `WriteThroughPolicyManagement` projects the removal into `PolicyState` only when a row was removed.

`RemoveTenantUseCase` makes no prediction beforehand. It asks for the removal, and only when nothing was removed does it consult the policy state to say why: the key is still a tenant, so it was the last one and the answer is `TenantPolicyFailure::LastTenant`; or it is not a tenant, and removing it was a no-op. By then the state reflects every write that completed before this one, which is what makes the answer reliable.

## Consequences

- Concurrent removals cannot empty the tenant set. `WriteThroughPolicyManagementTest::testConcurrentRemovalsNeverEmptyTheTenantSet` pins this.
- A rule that reads like business logic sits in a SQL predicate. Do not lift it back into the use case as a count check "so the store holds only data access": the check would be correct in every test that runs one call at a time and wrong in production.
- The use case keeps the *meaning* of the outcome — which failure a refused removal is — so the store still decides nothing about what the operator is told.
- Other check-then-write rules on the policy have the same shape in principle. `BanUseCase::banPubkey` refuses an active tenant from the in-memory state; a tenant added concurrently with the ban could be both. That leaves a banned tenant rather than a locked-out relay, is recoverable from the management API, and is not covered by this record.
