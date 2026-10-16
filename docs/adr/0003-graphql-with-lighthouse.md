# ADR-0003: Schema-first GraphQL with Lighthouse

- **Status:** Accepted
- **Date:** 2026-10-16

## Context

The main screen is a week grid: a location, seven days, each day's shifts, each shift's assignments, each assignee's
name and skills, plus any rule violations. As REST this is either many round trips or a bespoke "grid" endpoint that
grows a new query parameter for every screen. GraphQL fits a client that needs nested, screen-shaped reads, and
mutations that return either a result or a list of typed rule violations.

## Decision

- **Lighthouse (`nuwave/lighthouse`)**, schema-first: the `.graphql` schema is the contract and is reviewed in PRs.
  Simple fields use directives (`@belongsTo`, `@hasMany`, `@find`); domain operations (assign, publish, swap) are
  thin resolvers that call application services, which are also used by tests directly.
- **N+1 is prevented by Lighthouse's batch loaders** for relation directives, and a test asserts that the number of
  SQL queries for the week grid does not grow with the number of shifts.
- **Rule violations are data, not errors.** `assignStaff` returns `AssignStaffPayload { assignment, violations,
  blocked }`. Each `RuleViolation` has a rule code, pack id and version, severity and a human message. GraphQL
  `errors` are reserved for authentication, validation and not-found.
- **Auth:** Sanctum personal access tokens (`Authorization: Bearer`). The tenant comes from the token's user.
- **Abuse limits:** maximum query depth and complexity, introspection allowed only outside production.
- **Tenant isolation in resolvers** is not special code: resolvers use tenant-scoped models, and RLS backs them up.
  Another tenant's id behaves exactly like a nonexistent id.

## Consequences

- One endpoint, so HTTP caching and per-route rate limits are less useful; complexity limits stand in for the latter.
- Lighthouse adds a schema-parse cache that must be cleared on deploy (`lighthouse:clear-cache`).

## Alternatives rejected

- **REST + JSON:API includes:** workable, but the nested grid with violations becomes custom include logic.
- **Code-first (`rebing/graphql-laravel`):** more PHP boilerplate per type; the SDL is a better review artefact.
