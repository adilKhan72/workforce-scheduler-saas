# ADR-0004: Labour rules as code, parameters as versioned rule packs

- **Status:** Accepted
- **Date:** 2026-10-16

## Context

Labour rules differ by jurisdiction and change over time: maximum weekly hours, minimum rest between shifts, limits
for minors (daily hours, latest finish time), skill or certification requirements. A schedule from last year must
still be explainable with the rules that applied then.

## Decision

- **Each rule type is a small, pure PHP class** (`MaxHoursPerWeek`, `MinRestBetweenShifts`, `MaxHoursPerDay`,
  `MinorWorkerRestrictions`, `RequiredSkill`, `WithinAvailability`, `NoOverlappingShifts`). It takes the candidate shift, the staff member's
  other shifts in the affected window and its parameters, and returns violations. No database access, so each rule is
  unit-tested with plain value objects.
- **A rule pack is data:** a versioned, effective-dated JSON file (`api/resources/rule-packs/us-ny/2026.1.json`) listing rule
  instances, parameters and severity (`block` or `warn`). A location names its pack id; the engine picks the version
  effective on the shift's local date.
- **Explainable results:** a violation carries the rule code, pack id and version, severity, a message with the
  numbers ("Rest between shifts is 8.0 h; US-NY 2026.1 requires 10 h") and the conflicting shift ids.
- **Evaluated incrementally on assign** (one staff member, a bounded window) and **in full on publish**: publishing a
  week re-checks every assignment and refuses if any blocking violation remains.

## Consequences

- Adding a jurisdiction is a JSON file plus tests; adding a new kind of rule is a class plus tests.
- Packs ship with the code, so changing a parameter is a deploy. The design write-up keeps packs in a table so that
  tenant agreements can be layered on top; that layer is out of scope here.
- The packs in this repo are illustrative, simplified and **not legal advice**.

## Alternatives rejected

- **A rules DSL or a generic rules engine:** harder to test and to explain than ten small classes.
- **Hard-coded constants:** cannot answer "which rules applied to the schedule published in March".
