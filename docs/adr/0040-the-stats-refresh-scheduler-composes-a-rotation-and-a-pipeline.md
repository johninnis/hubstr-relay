# 40. The stats refresh scheduler composes a rotation and a pipeline

## Status

Accepted

Supersedes ADR-0016, which decided that the scheduler keeps its four constructor arguments and its three-step tick as one fenced unit. Carried forward: submit and apply are one operation because the in-flight guard spans both, and no parameter object may bundle collaborators that share no concept. Revised: the one-unit shape and the fence — the unit is now three named collaborators, because the fence the record relied on no longer holds. This record holds the complete current decision.

## Context

*Carried forward from ADR-0016.* On every tick the scheduler selects the next refresh from a fixed rotation, submits it to a worker pool, and applies the `PersistStatResultCommand` that comes back through the write coordinator. The single piece of state that makes it correct, `$inFlight`, is set at submission and cleared after application; it is what stops a tick launching a refresh while the previous one still runs. Splitting submission from application would put the guard on one side and the thing it guards on the other.

*What this record adds.* ADR-0016 kept selection, submission and application in one class and pinned the shape with a fence comment, on the reasoning that the steps exist only for each other and that no subset of the four arguments is a value. The ecosystem's static analyser since then stops accepting a fence as a reason to sit over the constructor-argument ceiling: a deliberate design must be expressed as named units the analyser can see, not as a comment asking to be left alone. ADR-0016's own escape hatch anticipated the way out — it noted that a cohesive subset with a name is extracted as a collaborator, "the count falling back under the threshold [as] the expected side effect of that extraction".

The rotation is that subset. It owns the cursor, the wrap-around and the tick interval, and it never touches the guard: a tick skipped because a refresh is in flight does not advance the cursor. What may not move is the pair the guard joins.

## Decision

- `StatsRefreshRotation` owns selection: the non-empty validation of the schedule, the cursor and its wrap, and the interval that spreads one window across the rotation.
- `StatsRefreshPipeline` owns submission and application together, with the in-flight guard inside it: `submit` sends the task to the pool and registers the future that the asynchronous apply clears. The guard is never split from what it guards.
- `StatsRefreshScheduler` is the lifecycle shell composing the two: it registers the tick at the rotation's interval, skips a tick while the pipeline is busy, and kills the pool through the pipeline on stop.

## Consequences

- Each collaborator takes at most three arguments and the analyser enforces the shape, where ADR-0016 could only ask a reader to respect it.
- The in-flight guard still cannot stack a slow aggregate behind itself, and a busy tick still does not consume the rotation.
- Do not move submission and application apart to slim the pipeline further: the guard spanning them is the correctness the shape exists for.
- Do not re-merge the three units: the split is what the analyser-enforced ceiling requires, and the pipeline boundary is drawn exactly where the guard allows one.
- `StatsRefreshSchedulerTest` pins, through the composed scheduler, the first tick submitting the totals task, the rotation advancing through the explore stats and wrapping, a busy tick being skipped without advancing, and a failed refresh being logged without halting the scheduler.
