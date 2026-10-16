# ADR-0002: Shifts are stored as local wall-clock time plus an IANA zone

- **Status:** Accepted
- **Date:** 2026-10-16

## Context

"Saturday 09:00–17:00 at the Brooklyn store" is a promise in local time. If we stored only UTC and the time-zone
rules changed (governments do this with little notice), every future shift would silently move. But labour rules
("10 hours rest between shifts", "40 hours a week") are about real elapsed time, and a 22:00–06:00 night shift is
7 hours long on the night clocks spring forward and 9 hours on the night they fall back.

## Decision

- The source of truth for a shift is `(local_date, start_local, end_local, timezone)`. `end_local <= start_local`
  means the shift ends the next day. The zone is copied from the location when the shift is created.
- `start_utc` and `end_utc` are **derived**, stored for indexing and overlap queries, and recomputed by one function
  (`ShiftTime`). `tzdb_version` records the PHP time-zone database version that computed them, so stale rows can be
  found and recomputed after a tzdata update.
- **Durations and rest gaps are computed from instants**, never from wall-clock differences.
- **Skipped local times are rejected.** A shift starting or ending at 02:30 on a spring-forward night does not exist;
  the API returns a validation error instead of silently moving it to 03:30 (PHP's default).
- **Ambiguous local times resolve to the first occurrence** (the earlier, daylight-time instant on a fall-back night),
  and the shift exposes `dstTransition: true` so the UI can show a marker.
- **"Day" and "week" are local** to the location: a week runs Monday 00:00 to Monday 00:00 local, so a DST week is
  167 or 169 hours long.

## Consequences

- DST behaviour is unit-tested with real America/New_York transitions (8 March and 1 November 2026) and
  Europe/London.
- Recomputing after a tzdata change is a batch job over rows whose `tzdb_version` differs. The job is described,
  not implemented, in this repo.
- Staff working across locations in different zones are compared on instants for rest rules, and on each
  location's local calendar for daily and weekly limits.

## Alternatives rejected

- **UTC only:** future shifts drift when zone rules change, and "what did the manager actually type" is lost.
- **Local time without a zone:** cannot compute hours across DST or compare shifts in different zones.
