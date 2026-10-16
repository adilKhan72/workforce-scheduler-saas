<?php

namespace Tests\Feature\GraphQL;

use App\Models\ShiftAssignment;
use App\Models\StaffMember;
use Illuminate\Support\Facades\DB;

final class ScheduleQueryTest extends GraphQLTestCase
{
    private const GRID = <<<'GQL'
        query ($locationId: ID!, $weekStart: String!) {
          schedule(locationId: $locationId, weekStart: $weekStart) {
            weekStart status publishedVersion
            location { name timezone }
            shifts {
              id localDate startLocal endLocal startUtc durationMinutes crossesDstTransition headcount
              assignments { id staffMember { id name skills } }
            }
          }
        }
    GQL;

    public function test_the_week_grid_returns_nested_shifts_assignments_and_staff(): void
    {
        $shift = $this->shift(['local_date' => '2026-10-31', 'start_local' => '22:00', 'end_local' => '06:00']);
        $this->asTenant($this->tenant, fn () => ShiftAssignment::factory()->create(['shift_id' => $shift->id, 'staff_member_id' => $this->adult->id]));

        $this->gql(self::GRID, ['locationId' => $this->location->id, 'weekStart' => '2026-10-28'])
            ->assertGraphQLErrorFree()
            ->assertJsonPath('data.schedule.weekStart', '2026-10-26')
            ->assertJsonPath('data.schedule.status', 'DRAFT')
            ->assertJsonPath('data.schedule.location.timezone', 'America/New_York')
            ->assertJsonPath('data.schedule.shifts.0.startLocal', '22:00')
            ->assertJsonPath('data.schedule.shifts.0.startUtc', '2026-11-01T02:00:00.000000Z')
            ->assertJsonPath('data.schedule.shifts.0.durationMinutes', 540)
            ->assertJsonPath('data.schedule.shifts.0.crossesDstTransition', true)
            ->assertJsonPath('data.schedule.shifts.0.assignments.0.staffMember.name', 'Ava');
    }

    public function test_an_unscheduled_week_is_empty_and_is_not_created_by_reading_it(): void
    {
        $this->gql(self::GRID, ['locationId' => $this->location->id, 'weekStart' => '2027-01-06'])
            ->assertGraphQLErrorFree()
            ->assertJsonPath('data.schedule.weekStart', '2027-01-04')
            ->assertJsonPath('data.schedule.shifts', []);

        $this->assertSame(0, $this->asTenant($this->tenant, fn () => DB::table('schedule_weeks')->where('week_start', '2027-01-04')->count()));
    }

    public function test_the_number_of_sql_queries_does_not_grow_with_the_number_of_shifts(): void
    {
        $this->seedWeek(shifts: 2);
        $small = $this->countQueries();

        $this->seedWeek(shifts: 12);
        $large = $this->countQueries();

        $this->assertSame($small, $large, "N+1: {$small} queries for 2 shifts, {$large} for 14");
        $this->assertLessThanOrEqual(12, $large);
    }

    public function test_another_tenants_location_is_not_found(): void
    {
        $other = $this->otherTenant();

        $this->gql(self::GRID, ['locationId' => $other['location']->id, 'weekStart' => '2026-10-19'])
            ->assertGraphQLErrorMessage('Not found.');

        $this->gql('query ($id: ID!) { location(id: $id) { id } }', ['id' => $other['location']->id])
            ->assertJsonPath('data.location', null);
    }

    public function test_lists_only_contain_the_callers_tenant(): void
    {
        $this->otherTenant();

        $this->gql('{ locations { name } staffMembers { name } }')
            ->assertGraphQLErrorFree()
            ->assertJsonCount(1, 'data.locations')
            ->assertJsonPath('data.staffMembers.*.name', ['Ava', 'Ella']);
    }

    public function test_every_field_requires_authentication(): void
    {
        $this->graphQL('{ locations { id } }')->assertGraphQLErrorMessage('Unauthenticated.');
    }

    public function test_a_real_bearer_token_works_end_to_end(): void
    {
        $token = $this->manager->createToken('test')->plainTextToken;

        $this->withToken($token)->graphQL('{ me { name tenant { name } } locations { name } }')
            ->assertGraphQLErrorFree()
            ->assertJsonPath('data.me.tenant.name', 'Northwind Coffee')
            ->assertJsonPath('data.locations.0.name', 'Brooklyn');
    }

    private function seedWeek(int $shifts): void
    {
        $this->asTenant($this->tenant, function () use ($shifts) {
            foreach (range(1, $shifts) as $i) {
                $shift = $this->shift(['local_date' => '2026-10-'.(19 + $i % 7), 'start_local' => sprintf('%02d:00', 6 + $i % 10), 'end_local' => '23:00', 'headcount' => 2]);
                foreach (StaffMember::factory()->count(2)->create() as $staff) {
                    ShiftAssignment::factory()->create(['shift_id' => $shift->id, 'staff_member_id' => $staff->id]);
                }
            }
        });
    }

    private function countQueries(): int
    {
        $count = 0;
        DB::listen(function () use (&$count) {
            $count++;
        });

        $this->gql(self::GRID, ['locationId' => $this->location->id, 'weekStart' => '2026-10-19'])->assertGraphQLErrorFree();
        $total = $count;
        DB::getEventDispatcher()->forget('Illuminate\Database\Events\QueryExecuted');

        return $total;
    }
}
