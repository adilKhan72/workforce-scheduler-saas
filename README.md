# workforce-scheduler-saas

A multi-tenant staff-scheduling SaaS: **Laravel 13 GraphQL API (Lighthouse) + Vue 3 / TypeScript dashboard**, on
PostgreSQL 17 with **row-level security** as a second line of tenant isolation.

Employers (tenants) have locations, staff (roles, skills, availability, contracted hours, minors) and weekly
schedules of shifts. Assigning someone to a shift is checked against a versioned **labour-rule pack** (maximum
weekly hours, minimum rest between shifts, minor-worker limits, skills). Managers publish a week; staff can request
swaps.

It implements a working slice of a system design I wrote up separately:
[Multi-tenant workforce scheduling SaaS](https://github.com/adilKhan72/system-design-notes/tree/main/designs/multi-tenant-workforce-scheduling-saas)
(tenancy model, wall-clock time + IANA zone storage, rule packs).

> **Status:** in progress. See the roadmap below.

## Design decisions

| ADR | Decision |
|---|---|
| [0001](docs/adr/0001-shared-schema-tenancy-with-postgres-rls.md) | Shared schema, `tenant_id` everywhere; app-level scope **and** PostgreSQL RLS (`FORCE`, transaction-local `app.tenant_id`, non-owner runtime role) |
| [0002](docs/adr/0002-local-wall-clock-time-plus-iana-zone.md) | Shifts stored as local date/time + IANA zone; UTC instants derived; DST-skipped times rejected |
| [0003](docs/adr/0003-graphql-with-lighthouse.md) | Schema-first GraphQL with Lighthouse; batch loading; violations as typed data |
| [0004](docs/adr/0004-labour-rules-as-versioned-rule-packs.md) | Rules as small pure classes; parameters as versioned, effective-dated JSON packs |

## Roadmap

- [x] **M1** Domain model, tenancy (scope + middleware + RLS), DST-aware shift times, CI on PostgreSQL 17
- [x] **M2** GraphQL API (Lighthouse + Sanctum), rule engine and packs, publish week, swap requests
- [ ] **M3** Vue 3 week-grid dashboard (assign staff, see violations, publish)

## GraphQL API

Schema-first with Lighthouse: [`api/graphql/schema.graphql`](api/graphql/schema.graphql). One endpoint,
`POST /graphql`, Sanctum bearer token. The tenant is taken from the token's user; another tenant's ids return the
same generic `Not found.` as ids that don't exist.

**The week grid** (7 SQL queries whether the week has 2 shifts or 200; a test enforces that the count does not grow):

```graphql
query Week($locationId: ID!, $weekStart: String!) {
  schedule(locationId: $locationId, weekStart: $weekStart) {
    weekStart status publishedVersion
    shifts {
      id localDate startLocal endLocal durationMinutes crossesDstTransition headcount requiredSkill
      assignments { id staffMember { id name skills } }
    }
    violations { rule severity message shiftIds }
  }
}
```

**Assigning staff** returns rule violations as typed data. Blocking ones refuse the change; warnings come back with
the new assignment:

```graphql
mutation { assignStaff(shiftId: "…", staffMemberId: "…") {
  blocked
  assignment { id }
  violations { rule severity packId packVersion message shiftIds }
} }
```

```json
{ "blocked": true, "assignment": null, "violations": [{
  "rule": "minor_worker_restrictions", "severity": "BLOCK", "packId": "us-ny", "packVersion": "2026.1",
  "message": "Ella (age 15) cannot work past 22:00. (us-ny 2026.1)" }] }
```

Other operations: `createShift` (local time; DST-skipped times rejected), `unassignStaff`, `publishWeek` (full
re-check; refused while any blocking violation remains; later edits put the week back to draft and re-publishing
bumps `publishedVersion`), `requestSwap` / `approveSwap` (re-runs the rules for the incoming person) / `rejectSwap`.

Limits: max depth 8, max complexity 300, introspection disabled when `APP_ENV=production`.

### Rule packs

[`api/resources/rule-packs/`](api/resources/rule-packs): versioned, effective-dated JSON per jurisdiction
(`us-ny` 2025.1 and 2026.1, `gb` 2026.1) over seven pure rule types: no overlapping shifts, required skill,
minimum rest, max hours per day/week, minor-worker restrictions (age on the shift date; daily/weekly hours,
earliest start, latest end) and availability. Illustrative and simplified, **not legal advice**.

## Run it

```bash
make key        # .env with a fresh APP_KEY
make up         # postgres 17, migrations (as owner), API on :8000 (as the non-owner runtime role)
make seed       # two demo tenants
curl -s localhost:8000/api/login -H 'Content-Type: application/json' \
  -d '{"email":"manager@northwind.test","password":"password"}'
```

## Tests

```bash
make test-api      # Pint + tests on SQLite (fast; RLS suite skipped with a reason)
make test-api-pg   # full suite on the compose PostgreSQL 17, as CI runs it
```

CI runs everything on PostgreSQL 17 and fails if any test is skipped there.

## License

MIT
