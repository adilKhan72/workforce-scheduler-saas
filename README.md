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

- [ ] **M1** Domain model, tenancy (scope + middleware + RLS), DST-aware shift times, CI on PostgreSQL 17
- [ ] **M2** GraphQL API (Lighthouse + Sanctum), rule engine and packs, publish week, swap requests
- [ ] **M3** Vue 3 week-grid dashboard (assign staff, see violations, publish)

## License

MIT
