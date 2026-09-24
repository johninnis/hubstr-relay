# 16. The stats refresh scheduler is one pipeline, not three units

## Status

Accepted

## Context

`StatsRefreshScheduler` reads like three responsibilities wearing one name. On every tick it selects the next refresh from a fixed rotation, submits it to a worker pool, and applies the `PersistStatResultCommand` that comes back through the write coordinator. Three verbs, and four constructor arguments to serve them. Both are classic signals of a unit that has taken on more than one job, so a reader arrives wanting either a split — a schedule, a runner, an applier — or a parameter object to bundle the arguments away.

Neither move survives contact with what the class does.

**The three verbs are three steps of one operation, not three responsibilities.** Each step exists only because of the one before it: nothing else selects a refresh, nothing else has a task to submit, nothing else has a result to apply. More decisively, the single piece of state that makes the class correct spans all three steps. `$inFlight` is set at submission and cleared after application, and it is what stops a tick launching a refresh while the previous one is still running — the guard that keeps a slow full-table aggregate from stacking up behind itself. Split the steps into separate units and that guard has to be shared between them, which is this class again with indirection in front of it.

**The four arguments are four separate things the tick needs, with no cohesive concept beneath them.** The pool the refresh runs on, the rotation it runs, the coordinator its result is applied through, and the logger a failed refresh is reported to. The rotation arrives already built, as a `ScheduledRefreshCollection`: the scheduler does not know what a database is, and does not construct the work it schedules. Folding any subset of the remaining four into a parameter object would lower the count without removing a responsibility, because a pool, a rotation, a coordinator and a logger are not a value.

## Decision

`StatsRefreshScheduler` keeps its four constructor arguments and its three-step tick as one unit. The constructor carries a one-line fence pointing at this record so the shape is not repeatedly re-litigated.

A fifth, defaulted argument sits outside the count: the `RepeatingTimer` that `start()` and `drain()` register the tick with. It is the loop-registration state every scheduler in the relay shares, extracted once rather than repeated per scheduler, and it takes no part in what a tick does.

This is a finding about one class, not a general licence. The pool, the schedule, the write coordinator and the logger are the four collaborators one pipeline needs, and none of them names a unit that could be lifted out: splitting selection from submission would put the in-flight guard on one side and the thing it guards on the other.

## Consequences

- One class carries the fence, and carries it for a stated structural reason — the `$inFlight` guard spanning all three steps — rather than as an exemption from a guideline.
- Do not "fix" the count with a parameter object: there is no cohesive concept behind the four arguments, so the smell would move rather than go.
- If the rotation ever grows behaviour of its own — per-task intervals, backoff, priority — that is a cohesive subset with a name, and it is extracted as a collaborator rather than left inline under this fence. The count falling back under the threshold is the expected side effect of that extraction, not a goal pursued for itself.
