<?php

namespace Tests\Feature\GraphQL;

use App\Models\ScheduleWeek;
use App\Models\ShiftAssignment;
use App\Models\StaffMember;

final class MutationsTest extends GraphQLTestCase
{
    private const ASSIGN = <<<'GQL'
        mutation ($shiftId: ID!, $staffMemberId: ID!) {
          assignStaff(shiftId: $shiftId, staffMemberId: $staffMemberId) {
            blocked
            assignment { id staffMember { name } }
            violations { rule severity packId packVersion message shiftIds }
          }
        }
    GQL;

    private const CREATE = <<<'GQL'
        mutation ($input: CreateShiftInput!) {
          createShift(input: $input) { id localDate startLocal endLocal timezone startUtc endUtc durationMinutes crossesDstTransition }
        }
    GQL;

    public function test_create_shift_derives_utc_and_real_duration_from_the_locations_zone(): void
    {
        $this->gql(self::CREATE, ['input' => [
            'locationId' => $this->location->id, 'localDate' => '2026-03-07', 'startLocal' => '22:00', 'endLocal' => '06:00', 'jobRole' => 'barista',
        ]])
            ->assertGraphQLErrorFree()
            ->assertJsonPath('data.createShift.timezone', 'America/New_York')
            ->assertJsonPath('data.createShift.startUtc', '2026-03-08T03:00:00.000000Z')
            ->assertJsonPath('data.createShift.endUtc', '2026-03-08T10:00:00.000000Z')
            ->assertJsonPath('data.createShift.durationMinutes', 420)
            ->assertJsonPath('data.createShift.crossesDstTransition', true);
    }

    public function test_create_shift_rejects_a_local_time_that_dst_skips(): void
    {
        $this->gql(self::CREATE, ['input' => [
            'locationId' => $this->location->id, 'localDate' => '2026-03-08', 'startLocal' => '02:30', 'endLocal' => '08:00', 'jobRole' => 'barista',
        ]])
            ->assertGraphQLErrorMessage('2026-03-08 02:30 does not exist in America/New_York (clocks skip it for daylight saving time).')
            ->assertJsonPath('errors.0.extensions.category', 'user_input');
    }

    public function test_create_shift_validates_input(): void
    {
        $this->gql(self::CREATE, ['input' => [
            'locationId' => $this->location->id, 'localDate' => '19/10/2026', 'startLocal' => '9am', 'endLocal' => '17:00', 'jobRole' => 'barista',
        ]])->assertGraphQLValidationKeys(['input.localDate', 'input.startLocal']);
    }

    public function test_assigning_returns_the_assignment_with_warnings(): void
    {
        $previous = $this->shift(['local_date' => '2026-10-19', 'start_local' => '14:00', 'end_local' => '23:00']);
        $this->asTenant($this->tenant, fn () => ShiftAssignment::factory()->create(['shift_id' => $previous->id, 'staff_member_id' => $this->adult->id]));
        $next = $this->shift(['local_date' => '2026-10-20', 'start_local' => '06:00', 'end_local' => '14:00']);

        $this->gql(self::ASSIGN, ['shiftId' => $next->id, 'staffMemberId' => $this->adult->id])
            ->assertGraphQLErrorFree()
            ->assertJsonPath('data.assignStaff.blocked', false)
            ->assertJsonPath('data.assignStaff.assignment.staffMember.name', 'Ava')
            ->assertJsonPath('data.assignStaff.violations.0.rule', 'min_rest_between_shifts')
            ->assertJsonPath('data.assignStaff.violations.0.severity', 'WARN')
            ->assertJsonPath('data.assignStaff.violations.0.packVersion', '2026.1')
            ->assertJsonPath('data.assignStaff.violations.0.shiftIds', [$next->id, $previous->id]);
    }

    public function test_a_blocking_violation_refuses_the_assignment_and_writes_nothing(): void
    {
        $late = $this->shift(['local_date' => '2026-10-19', 'start_local' => '16:00', 'end_local' => '23:00']);

        $this->gql(self::ASSIGN, ['shiftId' => $late->id, 'staffMemberId' => $this->minor->id])
            ->assertGraphQLErrorFree()
            ->assertJsonPath('data.assignStaff.blocked', true)
            ->assertJsonPath('data.assignStaff.assignment', null)
            ->assertJsonPath('data.assignStaff.violations.0.rule', 'minor_worker_restrictions')
            ->assertJsonPath('data.assignStaff.violations.0.severity', 'BLOCK');

        $this->assertSame(0, $this->asTenant($this->tenant, fn () => ShiftAssignment::count()));
    }

    public function test_headcount_and_double_assignment_are_refused(): void
    {
        $shift = $this->shift(['headcount' => 1]);

        $this->gql(self::ASSIGN, ['shiftId' => $shift->id, 'staffMemberId' => $this->adult->id])->assertJsonPath('data.assignStaff.blocked', false);
        $this->gql(self::ASSIGN, ['shiftId' => $shift->id, 'staffMemberId' => $this->adult->id])->assertJsonPath('data.assignStaff.violations.0.rule', 'already_assigned');

        $other = $this->asTenant($this->tenant, fn () => StaffMember::factory()->create());
        $this->gql(self::ASSIGN, ['shiftId' => $shift->id, 'staffMemberId' => $other->id])->assertJsonPath('data.assignStaff.violations.0.rule', 'headcount_full');
    }

    public function test_cross_tenant_ids_are_indistinguishable_from_missing_ones(): void
    {
        $other = $this->otherTenant();
        $mine = $this->shift();

        // Their shift, my staff / my shift, their staff / a random id: same generic error, nothing written.
        foreach ([
            [$other['shift']->id, $this->adult->id],
            [$mine->id, $other['staff']->id],
            [$mine->id, '0199f5a0-0000-7000-8000-000000000000'],
        ] as [$shiftId, $staffId]) {
            $this->gql(self::ASSIGN, ['shiftId' => $shiftId, 'staffMemberId' => $staffId])
                ->assertGraphQLErrorMessage('Not found.')
                ->assertJsonPath('errors.0.extensions.category', 'not_found');
        }

        $this->assertSame(0, $this->asTenant($this->tenant, fn () => ShiftAssignment::count()));
        $this->assertSame(0, $this->asTenant($other['tenant'], fn () => ShiftAssignment::count()));
    }

    public function test_publishing_is_refused_while_a_blocking_violation_exists(): void
    {
        $late = $this->shift(['local_date' => '2026-10-19', 'start_local' => '16:00', 'end_local' => '23:00']);
        // Simulate a violation that got in before the rule existed: write it directly, bypassing the check.
        $this->asTenant($this->tenant, fn () => ShiftAssignment::factory()->create(['shift_id' => $late->id, 'staff_member_id' => $this->minor->id]));

        $publish = 'mutation ($l: ID!, $w: String!) { publishWeek(locationId: $l, weekStart: $w) { published week { status publishedVersion } violations { rule severity } } }';

        $this->gql($publish, ['l' => $this->location->id, 'w' => '2026-10-19'])
            ->assertGraphQLErrorFree()
            ->assertJsonPath('data.publishWeek.published', false)
            ->assertJsonPath('data.publishWeek.week.status', 'DRAFT')
            ->assertJsonPath('data.publishWeek.violations.0.severity', 'BLOCK');

        $this->gql('mutation ($id: ID!) { unassignStaff(assignmentId: $id) { id } }', [
            'id' => $this->asTenant($this->tenant, fn () => ShiftAssignment::first()->id),
        ])->assertGraphQLErrorFree();

        $this->gql($publish, ['l' => $this->location->id, 'w' => '2026-10-19'])
            ->assertJsonPath('data.publishWeek.published', true)
            ->assertJsonPath('data.publishWeek.week.status', 'PUBLISHED')
            ->assertJsonPath('data.publishWeek.week.publishedVersion', 1);

        // A change after publishing puts the week back to draft; re-publishing bumps the version.
        $this->gql(self::ASSIGN, ['shiftId' => $late->id, 'staffMemberId' => $this->adult->id])->assertJsonPath('data.assignStaff.blocked', false);
        $this->assertSame(ScheduleWeek::DRAFT, $this->asTenant($this->tenant, fn () => ScheduleWeek::first()->status));

        $this->gql($publish, ['l' => $this->location->id, 'w' => '2026-10-19'])
            ->assertJsonPath('data.publishWeek.week.publishedVersion', 2);
    }

    public function test_week_violations_are_reported_once_per_problem(): void
    {
        $a = $this->shift(['local_date' => '2026-10-19', 'start_local' => '14:00', 'end_local' => '23:00']);
        $b = $this->shift(['local_date' => '2026-10-20', 'start_local' => '06:00', 'end_local' => '14:00']);
        $this->asTenant($this->tenant, function () use ($a, $b) {
            ShiftAssignment::factory()->create(['shift_id' => $a->id, 'staff_member_id' => $this->adult->id]);
            ShiftAssignment::factory()->create(['shift_id' => $b->id, 'staff_member_id' => $this->adult->id]);
        });

        $this->gql('query ($l: ID!) { schedule(locationId: $l, weekStart: "2026-10-19") { violations { rule } } }', ['l' => $this->location->id])
            ->assertGraphQLErrorFree()
            ->assertJsonPath('data.schedule.violations', [['rule' => 'min_rest_between_shifts']]);
    }
}
