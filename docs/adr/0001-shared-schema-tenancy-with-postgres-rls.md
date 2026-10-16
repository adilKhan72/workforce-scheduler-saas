# ADR-0001: Shared-schema tenancy, enforced twice: application scope and PostgreSQL RLS

- **Status:** Accepted
- **Date:** 2026-10-16

## Context

Every tenant (an employer) shares one database. A schedule, a staff member's date of birth or a swap request
leaking to another employer is the worst bug this product can have. The usual Laravel approach (a global scope that
adds `WHERE tenant_id = ?`) works until someone writes `DB::table(...)`, `withoutGlobalScopes()`, a raw report
query, or a new model that forgets the trait.

The system design this repo implements a slice of
([multi-tenant workforce scheduling SaaS](https://github.com/adilKhan72/system-design-notes/tree/main/designs/multi-tenant-workforce-scheduling-saas))
chooses pooled cells with row-level security for the long tail of tenants. This repo is one pooled cell.

## Decision

1. **One schema, `tenant_id` on every tenant-owned table.** Tenant ids are UUIDs.
2. **Primary control: the application.** The tenant is resolved from the authenticated user only (never from a
   header, sub-domain or request argument). A `BelongsToTenant` trait adds a global scope and fills `tenant_id` on
   create. With no tenant in context the scope returns no rows (fail closed).
3. **Backstop: PostgreSQL row-level security** on every tenant-owned table, with `FORCE ROW LEVEL SECURITY` and one
   policy:

   ```sql
   USING (tenant_id = nullif(current_setting('app.tenant_id', true), '')::uuid)
   WITH CHECK (same)
   ```

   An unset setting gives `NULL`, so the policy matches nothing: missing context fails closed here too.
4. **Transaction-local context.** A middleware wraps each authenticated request in a transaction and calls
   `set_config('app.tenant_id', <id>, true)`. Transaction-local settings cannot leak to the next request on a reused
   connection, and work behind a transaction-mode pooler (PgBouncer).
5. **The runtime database role is neither superuser nor table owner** (both bypass RLS). Migrations run as the
   owner; the API connects as `scheduler_app`, which only has DML grants.
6. **Cross-tenant references are impossible in the schema.** Child tables use composite foreign keys
   `(tenant_id, parent_id) → parent(tenant_id, id)`, so a shift cannot point at another tenant's location even
   with RLS disabled.
7. **Identity tables are global.** `tenants`, `users` and `personal_access_tokens` are read before the tenant is
   known (to authenticate), so they are not under RLS. They hold no scheduling data.
8. **Tests prove the backstop**, not just the scope: with the app scope bypassed (`DB::select`, `withoutGlobalScopes`)
   as the runtime role, reads of another tenant's rows return nothing and writes are rejected. A test fails if any
   table with a `tenant_id` column lacks forced RLS.

## Consequences

- A forgotten filter becomes an empty result instead of a breach.
- RLS policies are only exercised on PostgreSQL. SQLite tests cover the application scope; the RLS suite is skipped
  there with an explicit reason, and CI always runs on PostgreSQL 17.
- Every request holds a transaction for its duration. Acceptable for a short API request; long exports would need
  their own pattern (queue jobs call the same `TenantContext::run()`).
- Eloquent does not support composite primary keys, so the design's `(tenant_id, id)` primary key becomes
  `id` primary key plus a `UNIQUE (tenant_id, id)` that the composite foreign keys reference. Every index still
  leads with `tenant_id`.

## Alternatives rejected

- **Application scope only:** one missed filter is a breach.
- **Schema or database per tenant:** see the design write-up. Migration fan-out with no benefit at this scale.
- **`SET ROLE` per request from a superuser connection:** a SQL injection could `RESET ROLE`. Separate login roles
  remove that. (The test suite does use `SET ROLE` inside its per-test transaction, purely to run as the app role.)
