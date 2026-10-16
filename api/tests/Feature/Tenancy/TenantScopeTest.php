<?php

namespace Tests\Feature\Tenancy;

use App\Models\Location;
use App\Models\Shift;
use App\Models\StaffMember;
use App\Models\Tenant;
use App\Tenancy\MissingTenantContext;
use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Tests\TestCase;

/**
 * The application-level control (global scope + write guards). Runs on SQLite and PostgreSQL.
 */
final class TenantScopeTest extends TestCase
{
    public function test_queries_only_see_the_current_tenants_rows(): void
    {
        [$a, $b] = Tenant::factory()->count(2)->create();
        $this->asTenant($a, fn () => Location::factory()->count(2)->create());
        $this->asTenant($b, fn () => Location::factory()->count(3)->create());

        $this->assertSame(2, $this->asTenant($a, fn () => Location::count()));
        $this->assertSame(3, $this->asTenant($b, fn () => Location::count()));
    }

    public function test_without_a_tenant_context_queries_return_nothing(): void
    {
        $a = Tenant::factory()->create();
        $this->asTenant($a, fn () => Location::factory()->create());

        $this->assertSame(0, Location::count());
    }

    public function test_writing_without_a_tenant_context_is_refused(): void
    {
        $this->expectException(MissingTenantContext::class);

        Location::factory()->create();
    }

    public function test_writing_a_row_for_another_tenant_is_refused(): void
    {
        [$a, $b] = Tenant::factory()->count(2)->create();

        $this->expectException(MissingTenantContext::class);

        $this->asTenant($a, fn () => Location::factory()->create(['tenant_id' => $b->id]));
    }

    public function test_tenant_id_is_not_mass_assignable_but_filled_from_context(): void
    {
        $a = Tenant::factory()->create();

        $location = $this->asTenant($a, fn () => Location::create([
            'name' => 'Main', 'timezone' => 'UTC', 'rule_pack' => 'us-ny', 'tenant_id' => 'ignored',
        ]));

        $this->assertSame($a->id, $location->tenant_id);
    }

    public function test_a_shift_cannot_be_created_at_another_tenants_location(): void
    {
        [$a, $b] = Tenant::factory()->count(2)->create();
        $foreign = $this->asTenant($b, fn () => Location::factory()->create());

        $this->expectException(ModelNotFoundException::class);

        $this->asTenant($a, fn () => Shift::factory()->create(['location_id' => $foreign->id]));
    }

    public function test_nested_contexts_restore_the_outer_tenant(): void
    {
        [$a, $b] = Tenant::factory()->count(2)->create();
        $context = app(TenantContext::class);

        $context->run($a, function () use ($context, $b, $a) {
            $context->run($b, fn () => $this->assertSame($b->id, $context->id()));
            $this->assertSame($a->id, $context->id());
        });

        $this->assertNull($context->id());
    }

    public function test_shift_derives_utc_times_zone_and_week_from_the_location(): void
    {
        $a = Tenant::factory()->create();

        $shift = $this->asTenant($a, function () {
            $location = Location::factory()->create(['timezone' => 'America/New_York']);

            return Shift::factory()->create([
                'location_id' => $location->id, 'local_date' => '2026-10-31', 'start_local' => '22:00', 'end_local' => '06:00',
            ])->fresh(['scheduleWeek']);
        });

        $this->assertSame('America/New_York', $shift->timezone);
        $this->assertSame('2026-11-01 02:00:00', $shift->start_utc->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-01 11:00:00', $shift->end_utc->format('Y-m-d H:i:s'));
        $this->assertSame(540, $shift->time()->minutes());
        $this->assertStringStartsWith('2026-10-26', (string) $shift->scheduleWeek->week_start);
        $this->assertSame(timezone_version_get(), $shift->tzdb_version);
    }

    public function test_staff_age_is_evaluated_on_the_shift_date(): void
    {
        $a = Tenant::factory()->create();
        $staff = $this->asTenant($a, fn () => StaffMember::factory()->minor('2010-10-20')->create());

        $this->assertSame(15, $staff->ageOn('2026-10-19'));
        $this->assertSame(16, $staff->ageOn('2026-10-20'));
    }
}
