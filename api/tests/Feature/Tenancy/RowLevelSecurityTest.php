<?php

namespace Tests\Feature\Tenancy;

use App\Models\Location;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\StaffMember;
use App\Models\Tenant;
use App\Tenancy\Rls;
use App\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The backstop: PostgreSQL row-level security, tested with the application scope bypassed
 * (raw SQL, withoutGlobalScopes) while running as the runtime role. PostgreSQL only.
 */
final class RowLevelSecurityTest extends TestCase
{
    private Tenant $a;

    private Tenant $b;

    /** @var array<string, string> ids of tenant B's rows */
    private array $bIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (! $this->onPostgres()) {
            $this->markTestSkipped('Row-level security is PostgreSQL-only; CI runs this suite on PostgreSQL 17.');
        }

        [$this->a, $this->b] = Tenant::factory()->count(2)->create();

        foreach ([$this->a, $this->b] as $tenant) {
            $ids = $this->asTenant($tenant, function () {
                $assignment = ShiftAssignment::factory()->create();

                return [
                    'locations' => $assignment->shift->location_id,
                    'shifts' => $assignment->shift_id,
                    'staff_members' => $assignment->staff_member_id,
                    'shift_assignments' => $assignment->id,
                ];
            });
            if ($tenant->is($this->b)) {
                $this->bIds = $ids;
            }
        }
    }

    public function test_the_runtime_role_cannot_bypass_rls(): void
    {
        $role = DB::selectOne('select current_user as name, rolsuper, rolbypassrls from pg_roles where rolname = current_user');

        $this->assertSame(Rls::appRole(), $role->name);
        $this->assertFalse($role->rolsuper);
        $this->assertFalse($role->rolbypassrls);

        $owned = DB::selectOne("select count(*) as n from pg_tables where schemaname = 'public' and tableowner = current_user");
        $this->assertSame(0, $owned->n, 'The runtime role must not own tables (owners bypass RLS unless FORCEd).');
    }

    public function test_every_table_with_a_tenant_id_has_forced_rls_and_a_policy(): void
    {
        $tables = DB::select(<<<'SQL'
            select c.relname, c.relrowsecurity, c.relforcerowsecurity,
                   exists (select 1 from pg_policies p where p.tablename = c.relname) as has_policy
            from pg_class c
            join pg_namespace n on n.oid = c.relnamespace and n.nspname = 'public'
            join information_schema.columns col on col.table_schema = 'public' and col.table_name = c.relname and col.column_name = 'tenant_id'
            where c.relkind = 'r'
            order by c.relname
        SQL);

        $global = ['users']; // identity table, read before the tenant is known (ADR-0001)
        $checked = [];

        foreach ($tables as $t) {
            if (in_array($t->relname, $global, true)) {
                continue;
            }
            $checked[] = $t->relname;
            $this->assertTrue($t->relrowsecurity && $t->relforcerowsecurity && $t->has_policy, "{$t->relname} lacks forced RLS or a policy");
        }

        $this->assertContains('shifts', $checked);
        $this->assertGreaterThanOrEqual(6, count($checked));
    }

    public function test_control_the_table_owner_sees_both_tenants(): void
    {
        // Guards against a vacuous pass: the data for both tenants is really there.
        DB::statement('RESET ROLE');
        $this->assertSame(2, DB::selectOne('select count(distinct tenant_id) as n from shifts')->n);
        DB::statement('SET ROLE "'.Rls::appRole().'"');
    }

    public function test_raw_reads_only_return_the_current_tenants_rows(): void
    {
        $this->asTenant($this->a, function () {
            foreach (array_keys($this->bIds) as $table) {
                $tenants = collect(DB::select("select distinct tenant_id from {$table}"))->pluck('tenant_id')->all();
                $this->assertSame([$this->a->id], $tenants, $table);
            }

            // Asking for B's rows by id, with the Eloquent scope removed, still finds nothing.
            $this->assertNull(Location::withoutGlobalScopes()->find($this->bIds['locations']));
            $this->assertSame(1, Shift::withoutGlobalScopes()->count());
        });
    }

    public function test_without_a_tenant_setting_nothing_is_visible(): void
    {
        DB::transaction(function () {
            foreach (array_keys($this->bIds) as $table) {
                $this->assertSame(0, DB::selectOne("select count(*) as n from {$table}")->n, $table);
            }
        });
    }

    public function test_raw_writes_to_another_tenant_are_rejected_or_affect_nothing(): void
    {
        $this->asTenant($this->a, function () {
            $this->assertSame(0, DB::update('update shifts set headcount = 99 where id = ?', [$this->bIds['shifts']]));
            $this->assertSame(0, DB::delete('delete from shift_assignments where id = ?', [$this->bIds['shift_assignments']]));
            $this->assertSame(0, StaffMember::withoutGlobalScopes()->whereKey($this->bIds['staff_members'])->update(['name' => 'pwned']));
        });

        $this->asTenant($this->b, function () {
            $this->assertSame(1, (int) DB::selectOne('select headcount from shifts where id = ?', [$this->bIds['shifts']])->headcount);
            $this->assertSame(1, ShiftAssignment::count());
        });
    }

    public function test_inserting_a_row_for_another_tenant_violates_the_policy(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('row-level security');

        $this->asTenant($this->a, fn () => DB::insert(
            'insert into locations (id, tenant_id, name, timezone, rule_pack) values (?, ?, ?, ?, ?)',
            [(string) Str::uuid7(), $this->b->id, 'Injected', 'UTC', 'us-ny'],
        ));
    }

    public function test_moving_a_row_to_another_tenant_violates_the_policy(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('row-level security');

        $this->asTenant($this->a, fn () => DB::update('update staff_members set tenant_id = ?', [$this->b->id]));
    }

    public function test_composite_foreign_keys_block_references_to_another_tenants_rows(): void
    {
        // Even a correctly-tenanted row cannot point at B's location: (A, B-location) does not exist.
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('foreign key');

        $this->asTenant($this->a, function () {
            $week = DB::selectOne('select id from schedule_weeks limit 1')->id;
            DB::insert(
                "insert into shifts (id, tenant_id, location_id, schedule_week_id, local_date, start_local, end_local, timezone, start_utc, end_utc, tzdb_version, job_role)
                 values (?, ?, ?, ?, '2026-10-19', '09:00', '17:00', 'UTC', now(), now(), 'x', 'barista')",
                [(string) Str::uuid7(), $this->a->id, $this->bIds['locations'], $week],
            );
        });
    }

    public function test_the_setting_is_cleared_when_the_tenant_context_ends(): void
    {
        app(TenantContext::class)->run($this->a, fn () => null);

        DB::transaction(function () {
            $this->assertSame('', (string) DB::selectOne("select current_setting('app.tenant_id', true) as v")->v);
        });
    }
}
