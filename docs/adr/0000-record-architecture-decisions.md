# 0. Record architecture decisions

## Status

Accepted

## Context

Architectural decisions and the reasoning behind them are easily lost. Once the rationale is gone, a later reader cannot tell a deliberate choice from an accident, and "corrects" designs that were right.

## Decision

We record architecture decisions in this directory, one immutable file per decision, numbered sequentially. Each record has exactly four sections: **Status**, **Context**, **Decision**, **Consequences**.

Until the first tagged release, every record is kept true to the code by editing it in place: a record that describes anything other than the codebase as it stands is wrong, and there is no published history for a supersession chain to protect. From the first tagged release onward a record is never edited to change its decision; it is superseded by a new record, and its Status is set to `Superseded by ADR-NNNN`.

## Consequences

- The reasoning behind each decision is preserved alongside the code and survives the people who made it.
- Before the first release, revisiting a decision means rewriting its record so the set always reads as one current account, with no amendments to reassemble. After it, revisiting a decision means writing a new record, not rewriting history.
