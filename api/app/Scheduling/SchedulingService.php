<?php

namespace App\Scheduling;

use App\Models\Location;
use App\Models\ScheduleWeek;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\StaffMember;
use App\Models\SwapRequest;
use App\Models\User;
use App\Rules\Candidate;
use App\Rules\RuleEngine;
use App\Rules\RulePackRepository;
use App\Rules\RuleViolation;
use App\Rules\Severity;
use App\Rules\ShiftSnapshot;
use App\Rules\StaffSnapshot;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Application service behind the GraphQL mutations. Every query goes through tenant-scoped models,
 * so another tenant's id behaves exactly like a nonexistent one (ModelNotFoundException).
 */
final class SchedulingService
{
    /** How far around a shift to look for the staff member's other shifts (rest + week rules). */
    private const WINDOW_DAYS = 8;

    public function __construct(
        private readonly RuleEngine $engine,
        private readonly RulePackRepository $packs,
    ) {}

    /**
     * @param  array{location_id: string, local_date: string, start_local: string, end_local: string, job_role: string, required_skill?: ?string, headcount?: ?int}  $input
     */
    public function createShift(array $input): Shift
    {
        return DB::transaction(function () use ($input) {
            $location = Location::findOrFail($input['location_id']);
            // Validate the wall-clock definition before touching the database (throws InvalidShiftTime).
            ShiftTime::resolve($input['local_date'], $input['start_local'], $input['end_local'], $location->timezone);

            $shift = Shift::create([
                'location_id' => $location->id,
                'local_date' => $input['local_date'],
                'start_local' => $input['start_local'],
                'end_local' => $input['end_local'],
                'job_role' => $input['job_role'],
                'required_skill' => $input['required_skill'] ?? null,
                'headcount' => $input['headcount'] ?? 1,
            ]);
            $shift->scheduleWeek->markChanged();

            return $shift;
        });
    }

    /**
     * Incremental evaluation on edit: one staff member, a bounded window (ADR-0004).
     * Blocking violations refuse the assignment; warnings are returned alongside it.
     */
    public function assignStaff(string $shiftId, string $staffMemberId): AssignmentResult
    {
        return DB::transaction(function () use ($shiftId, $staffMemberId) {
            // Lock order: shift, then staff. The staff lock serialises concurrent assignments of the same
            // person, so two parallel requests cannot both pass the weekly-hours check.
            $shift = Shift::lockForUpdate()->findOrFail($shiftId);
            $staff = StaffMember::lockForUpdate()->findOrFail($staffMemberId);
            $shift->load('location', 'assignments');
            $staff->load('availabilities');

            if ($shift->assignments->contains('staff_member_id', $staff->id)) {
                return new AssignmentResult(null, [$this->stateViolation($shift, $staff, 'already_assigned', "{$staff->name} is already on this shift.")]);
            }

            if ($shift->assignments->count() >= $shift->headcount) {
                return new AssignmentResult(null, [$this->stateViolation($shift, $staff, 'headcount_full', "This shift already has {$shift->headcount} of {$shift->headcount} people.")]);
            }

            $violations = $this->evaluate($shift, $staff, $this->otherShiftsOf($staff->id, $shift));

            if ($this->hasBlocking($violations)) {
                return new AssignmentResult(null, $violations);
            }

            $assignment = ShiftAssignment::create(['shift_id' => $shift->id, 'staff_member_id' => $staff->id]);
            $shift->scheduleWeek->markChanged();

            return new AssignmentResult($assignment, $violations);
        });
    }

    public function unassign(string $assignmentId): Shift
    {
        return DB::transaction(function () use ($assignmentId) {
            $assignment = ShiftAssignment::with('shift.scheduleWeek')->findOrFail($assignmentId);
            $shift = $assignment->shift;
            $assignment->delete();
            $shift->scheduleWeek->markChanged();

            return $shift;
        });
    }

    public function weekFor(string $locationId, string $weekStart): ScheduleWeek
    {
        $location = Location::findOrFail($locationId);
        $monday = ShiftTime::weekStart($weekStart);

        // Reads never create rows: an unscheduled week is returned unsaved (no shifts, draft).
        return ScheduleWeek::where('location_id', $location->id)->where('week_start', $monday)->first()
            ?? (new ScheduleWeek(['location_id' => $location->id, 'week_start' => $monday]))->setRelation('location', $location);
    }

    /**
     * Full evaluation of every assignment in a week (ADR-0004). A handful of queries regardless of size.
     *
     * @return list<RuleViolation>
     */
    public function evaluateWeek(ScheduleWeek $week): array
    {
        if (! $week->exists) {
            return [];
        }

        $assignments = ShiftAssignment::query()
            ->whereIn('shift_id', $week->shifts()->select('id'))
            ->with(['shift.location', 'staffMember.availabilities'])
            ->get();

        if ($assignments->isEmpty()) {
            return [];
        }

        $byStaff = $this->assignedShiftsInWindow(
            $assignments->pluck('staff_member_id')->unique()->values(),
            $assignments->min(fn ($a) => $a->shift->start_utc)->subDays(self::WINDOW_DAYS),
            $assignments->max(fn ($a) => $a->shift->end_utc)->addDays(self::WINDOW_DAYS),
        );

        $violations = [];
        foreach ($assignments as $assignment) {
            $others = $byStaff->get($assignment->staff_member_id, collect())
                ->reject(fn (Shift $s) => $s->id === $assignment->shift_id)
                ->map(fn (Shift $s) => ShiftSnapshot::fromModel($s))
                ->values()->all();

            array_push($violations, ...$this->evaluate($assignment->shift, $assignment->staffMember, $others));
        }

        return $this->dedupe($violations);
    }

    /**
     * Publishing re-checks everything and refuses while any blocking violation remains.
     *
     * @return array{week: ScheduleWeek, published: bool, violations: list<RuleViolation>}
     */
    public function publishWeek(string $locationId, string $weekStart): array
    {
        return DB::transaction(function () use ($locationId, $weekStart) {
            $week = $this->weekFor($locationId, $weekStart);

            if (! $week->exists) {
                throw new SchedulingError('There are no shifts to publish in this week.');
            }

            $week = ScheduleWeek::lockForUpdate()->findOrFail($week->id);
            $violations = $this->evaluateWeek($week);

            if ($this->hasBlocking($violations)) {
                return ['week' => $week, 'published' => false, 'violations' => $violations];
            }

            $week->update([
                'status' => ScheduleWeek::PUBLISHED,
                'published_version' => $week->published_version + 1,
                'published_at' => now(),
            ]);

            return ['week' => $week, 'published' => true, 'violations' => $violations];
        });
    }

    public function requestSwap(string $assignmentId, string $toStaffMemberId, ?string $reason): SwapRequest
    {
        return DB::transaction(function () use ($assignmentId, $toStaffMemberId, $reason) {
            $assignment = ShiftAssignment::findOrFail($assignmentId);
            $to = StaffMember::findOrFail($toStaffMemberId);

            if ($to->id === $assignment->staff_member_id) {
                throw new SchedulingError('A shift cannot be swapped to the person already working it.');
            }

            return SwapRequest::create([
                'shift_assignment_id' => $assignment->id,
                'from_staff_member_id' => $assignment->staff_member_id,
                'to_staff_member_id' => $to->id,
                'reason' => $reason,
            ]);
        });
    }

    /**
     * Approving re-runs the rules for the incoming person; a blocking violation leaves it pending.
     *
     * @return array{swap: SwapRequest, approved: bool, violations: list<RuleViolation>}
     */
    public function approveSwap(string $swapId, User $manager): array
    {
        return DB::transaction(function () use ($swapId, $manager) {
            $swap = SwapRequest::lockForUpdate()->findOrFail($swapId);
            $this->assertPending($swap);

            $assignment = ShiftAssignment::lockForUpdate()->findOrFail($swap->shift_assignment_id);
            $shift = Shift::with('location', 'scheduleWeek')->findOrFail($assignment->shift_id);
            $to = StaffMember::lockForUpdate()->findOrFail($swap->to_staff_member_id);
            $to->load('availabilities');

            $violations = $this->evaluate($shift, $to, $this->otherShiftsOf($to->id, $shift));

            if ($this->hasBlocking($violations)) {
                return ['swap' => $swap, 'approved' => false, 'violations' => $violations];
            }

            $assignment->update(['staff_member_id' => $to->id]);
            $this->decide($swap, SwapRequest::APPROVED, $manager);
            $shift->scheduleWeek->markChanged();

            return ['swap' => $swap, 'approved' => true, 'violations' => $violations];
        });
    }

    public function rejectSwap(string $swapId, User $manager): SwapRequest
    {
        return DB::transaction(function () use ($swapId, $manager) {
            $swap = SwapRequest::lockForUpdate()->findOrFail($swapId);
            $this->assertPending($swap);
            $this->decide($swap, SwapRequest::REJECTED, $manager);

            return $swap;
        });
    }

    /**
     * @param  list<ShiftSnapshot>  $others
     * @return list<RuleViolation>
     */
    private function evaluate(Shift $shift, StaffMember $staff, array $others): array
    {
        $pack = $this->packs->effectiveOn($shift->location->rule_pack, $shift->local_date);

        return $this->engine->evaluate($pack, new Candidate(
            StaffSnapshot::fromModel($staff), ShiftSnapshot::fromModel($shift), $others,
        ));
    }

    /** @return list<ShiftSnapshot> */
    private function otherShiftsOf(string $staffId, Shift $shift): array
    {
        return $this->assignedShiftsInWindow(
            collect([$staffId]),
            $shift->start_utc->subDays(self::WINDOW_DAYS),
            $shift->end_utc->addDays(self::WINDOW_DAYS),
        )->get($staffId, collect())
            ->reject(fn (Shift $s) => $s->id === $shift->id)
            ->map(fn (Shift $s) => ShiftSnapshot::fromModel($s))
            ->values()->all();
    }

    /**
     * One query: every shift the given staff work inside a UTC window, grouped by staff id.
     *
     * @param  Collection<int, string>  $staffIds
     * @return Collection<string, Collection<int, Shift>>
     */
    private function assignedShiftsInWindow(Collection $staffIds, $from, $to): Collection
    {
        $rows = ShiftAssignment::query()
            ->whereIn('staff_member_id', $staffIds)
            ->whereHas('shift', fn ($q) => $q->where('start_utc', '<', $to)->where('end_utc', '>', $from))
            ->with('shift')
            ->get();

        return $rows->groupBy('staff_member_id')->map(fn (Collection $group) => $group->pluck('shift'));
    }

    /** @param list<RuleViolation> $violations */
    private function hasBlocking(array $violations): bool
    {
        return array_any($violations, fn (RuleViolation $v) => $v->isBlocking());
    }

    /**
     * A full evaluation finds the same problem more than once: a pairwise violation (rest, overlap)
     * from both shifts, and a weekly total from every shift in that week. Keep one of each.
     *
     * @param  list<RuleViolation>  $violations
     * @return list<RuleViolation>
     */
    private function dedupe(array $violations): array
    {
        $byShifts = [];
        foreach ($violations as $v) {
            $byShifts[$v->key()] ??= $v;
        }

        $byMessage = [];
        foreach ($byShifts as $v) {
            $byMessage[$v->rule.'|'.$v->staffMemberId.'|'.$v->message] ??= $v;
        }

        return array_values($byMessage);
    }

    private function stateViolation(Shift $shift, StaffMember $staff, string $rule, string $message): RuleViolation
    {
        return new RuleViolation($rule, Severity::Block, 'system', '1', $message, $staff->id, [$shift->id]);
    }

    private function assertPending(SwapRequest $swap): void
    {
        if ($swap->status !== SwapRequest::PENDING) {
            throw new SchedulingError("This swap request is already {$swap->status}.");
        }
    }

    private function decide(SwapRequest $swap, string $status, User $manager): void
    {
        $swap->forceFill(['status' => $status, 'decided_by_user_id' => $manager->id, 'decided_at' => now()])->save();
    }
}
