<?php

namespace Tests\Feature\GraphQL;

use App\Models\ShiftAssignment;
use App\Models\StaffMember;

final class SwapRequestTest extends GraphQLTestCase
{
    private const REQUEST = 'mutation ($a: ID!, $to: ID!) { requestSwap(assignmentId: $a, toStaffMemberId: $to, reason: "dentist") { id status fromStaffMember { name } toStaffMember { name } } }';

    private const APPROVE = 'mutation ($id: ID!) { approveSwap(id: $id) { approved swapRequest { status decidedAt assignment { staffMember { name } } } violations { rule severity } } }';

    public function test_an_approved_swap_reassigns_the_shift(): void
    {
        [$assignment, $ben] = $this->assignedShift('09:00', '17:00');

        $id = $this->gql(self::REQUEST, ['a' => $assignment->id, 'to' => $ben->id])
            ->assertGraphQLErrorFree()
            ->assertJsonPath('data.requestSwap.status', 'PENDING')
            ->assertJsonPath('data.requestSwap.fromStaffMember.name', 'Ava')
            ->json('data.requestSwap.id');

        $this->gql(self::APPROVE, ['id' => $id])
            ->assertGraphQLErrorFree()
            ->assertJsonPath('data.approveSwap.approved', true)
            ->assertJsonPath('data.approveSwap.swapRequest.status', 'APPROVED')
            ->assertJsonPath('data.approveSwap.swapRequest.assignment.staffMember.name', 'Ben');

        $this->gql(self::APPROVE, ['id' => $id])->assertGraphQLErrorMessage('This swap request is already approved.');
    }

    public function test_approval_re_runs_the_rules_for_the_incoming_person(): void
    {
        [$assignment] = $this->assignedShift('16:00', '23:00');

        $id = $this->gql(self::REQUEST, ['a' => $assignment->id, 'to' => $this->minor->id])->json('data.requestSwap.id');

        $this->gql(self::APPROVE, ['id' => $id])
            ->assertJsonPath('data.approveSwap.approved', false)
            ->assertJsonPath('data.approveSwap.swapRequest.status', 'PENDING')
            ->assertJsonPath('data.approveSwap.swapRequest.assignment.staffMember.name', 'Ava')
            ->assertJsonPath('data.approveSwap.violations.0.rule', 'minor_worker_restrictions');

        $this->gql('mutation ($id: ID!) { rejectSwap(id: $id) { status } }', ['id' => $id])
            ->assertJsonPath('data.rejectSwap.status', 'REJECTED');

        $this->gql('{ swapRequests(status: REJECTED) { id } }')->assertJsonPath('data.swapRequests.0.id', $id);
    }

    public function test_a_swap_to_the_same_person_is_refused(): void
    {
        [$assignment] = $this->assignedShift('09:00', '17:00');

        $this->gql(self::REQUEST, ['a' => $assignment->id, 'to' => $this->adult->id])
            ->assertGraphQLErrorMessage('A shift cannot be swapped to the person already working it.');
    }

    /** @return array{ShiftAssignment, StaffMember} */
    private function assignedShift(string $start, string $end): array
    {
        $shift = $this->shift(['local_date' => '2026-10-21', 'start_local' => $start, 'end_local' => $end]);

        return $this->asTenant($this->tenant, fn () => [
            ShiftAssignment::factory()->create(['shift_id' => $shift->id, 'staff_member_id' => $this->adult->id]),
            StaffMember::factory()->create(['name' => 'Ben']),
        ]);
    }
}
