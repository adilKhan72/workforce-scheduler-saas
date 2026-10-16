<?php

namespace Tests\Unit\Rules;

use App\Rules\Candidate;
use App\Rules\Finding;
use App\Rules\ShiftSnapshot;
use App\Rules\StaffSnapshot;
use App\Rules\Types\MaxHoursPerDay;
use App\Rules\Types\MaxHoursPerWeek;
use App\Rules\Types\MinorWorkerRestrictions;
use App\Rules\Types\MinRestBetweenShifts;
use App\Rules\Types\NoOverlappingShifts;
use App\Rules\Types\RequiredSkill;
use App\Rules\Types\WithinAvailability;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Rule types are pure functions over snapshots: no database, no framework.
 */
final class RuleTypesTest extends TestCase
{
    private const MINOR_PARAMS = [
        'under_age' => 18, 'max_hours_per_day' => 8, 'max_hours_per_week' => 28,
        'earliest_start_local' => '06:00', 'latest_end_local' => '22:00',
    ];

    public function test_rest_gap_is_measured_in_real_time_across_spring_forward(): void
    {
        // Close Sat 7 Mar 17:00 -> Sun 01:00 EST (06:00Z); open Sun 8 Mar 11:00 EDT (15:00Z).
        // Wall clock says 10 h of rest; clocks went forward, so it is really 9 h.
        $close = ShiftSnapshot::local('close', '2026-03-07', '17:00', '01:00');
        $open = ShiftSnapshot::local('open', '2026-03-08', '11:00', '19:00');

        $findings = $this->check(new MinRestBetweenShifts, $open, [$close], ['min_hours' => 10]);

        $this->assertCount(1, $findings);
        $this->assertStringContainsString('is 9.0 h; at least 10 h required', $findings[0]->message);
        $this->assertSame(['open', 'close'], $findings[0]->shiftIds);
    }

    public function test_enough_rest_in_either_direction_passes(): void
    {
        $mon = ShiftSnapshot::local('mon', '2026-10-19', '06:00', '14:00');
        $tue = ShiftSnapshot::local('tue', '2026-10-20', '06:00', '14:00');

        $this->assertSame([], $this->check(new MinRestBetweenShifts, $mon, [$tue], ['min_hours' => 11]));
        $this->assertSame([], $this->check(new MinRestBetweenShifts, $tue, [$mon], ['min_hours' => 11]));
    }

    public function test_weekly_hours_count_the_extra_fall_back_hour(): void
    {
        // Five 22:00-06:00 nights Mon 26 Oct .. Fri 30 Oct are 8 h each, Sat 31 Oct is 9 h (fall back):
        // wall clock says 48 h, real time is 49 h.
        $nights = array_map(
            fn (string $d) => ShiftSnapshot::local("n{$d}", "2026-10-{$d}", '22:00', '06:00'),
            ['26', '27', '28', '29', '30'],
        );
        $saturday = ShiftSnapshot::local('sat', '2026-10-31', '22:00', '06:00');

        $findings = $this->check(new MaxHoursPerWeek, $saturday, $nights, ['max_hours' => 48]);

        $this->assertCount(1, $findings);
        $this->assertStringContainsString('49.0 h in the week of 2026-10-26', $findings[0]->message);
    }

    public function test_weekly_hours_ignore_shifts_in_other_weeks(): void
    {
        $lastWeek = ShiftSnapshot::local('prev', '2026-10-18', '06:00', '22:00'); // Sunday of the previous week
        $monday = ShiftSnapshot::local('mon', '2026-10-19', '06:00', '14:00');

        $this->assertSame([], $this->check(new MaxHoursPerWeek, $monday, [$lastWeek], ['max_hours' => 10]));
    }

    public function test_daily_hours(): void
    {
        $morning = ShiftSnapshot::local('am', '2026-10-19', '06:00', '12:00');
        $evening = ShiftSnapshot::local('pm', '2026-10-19', '13:00', '20:00');

        $this->assertCount(1, $this->check(new MaxHoursPerDay, $evening, [$morning], ['max_hours' => 12]));
        $this->assertSame([], $this->check(new MaxHoursPerDay, $evening, [], ['max_hours' => 12]));
    }

    public function test_overlap_is_detected_across_locations_in_different_zones(): void
    {
        // New York 09:00-17:00 EDT = 13:00-21:00Z; London 20:00-23:00 BST = 19:00-22:00Z.
        $ny = ShiftSnapshot::local('ny', '2026-10-19', '09:00', '17:00', 'America/New_York');
        $london = ShiftSnapshot::local('ldn', '2026-10-19', '20:00', '23:00', 'Europe/London');

        $this->assertCount(1, $this->check(new NoOverlappingShifts, $london, [$ny], []));
    }

    public function test_back_to_back_shifts_do_not_overlap(): void
    {
        $a = ShiftSnapshot::local('a', '2026-10-19', '06:00', '14:00');
        $b = ShiftSnapshot::local('b', '2026-10-19', '14:00', '22:00');

        $this->assertSame([], $this->check(new NoOverlappingShifts, $b, [$a], []));
    }

    public function test_minor_restrictions_apply_by_age_on_the_shift_date(): void
    {
        $late = ShiftSnapshot::local('late', '2026-10-19', '15:00', '23:00');

        $fifteen = $this->staff(dob: '2011-03-01');
        $findings = (new MinorWorkerRestrictions)->check(new Candidate($fifteen, $late, []), self::MINOR_PARAMS);
        $this->assertCount(1, $findings);
        $this->assertStringContainsString('(age 15) cannot work past 22:00', $findings[0]->message);

        // Turns 18 on the day of the shift: adult rules only.
        $eighteen = $this->staff(dob: '2008-10-19');
        $this->assertSame([], (new MinorWorkerRestrictions)->check(new Candidate($eighteen, $late, []), self::MINOR_PARAMS));
    }

    public function test_minor_weekly_daily_and_start_limits(): void
    {
        $minor = $this->staff(dob: '2010-01-01');
        $early = ShiftSnapshot::local('early', '2026-10-23', '05:00', '14:00'); // 9 h, starts before 06:00
        $week = [
            ShiftSnapshot::local('m', '2026-10-19', '15:00', '22:00'),
            ShiftSnapshot::local('t', '2026-10-20', '15:00', '22:00'),
            ShiftSnapshot::local('w', '2026-10-21', '15:00', '22:00'),
        ];

        $messages = array_map(fn (Finding $f) => $f->message, (new MinorWorkerRestrictions)->check(new Candidate($minor, $early, $week), self::MINOR_PARAMS));

        $this->assertCount(3, $messages);
        $this->assertStringContainsString('9.0 h on 2026-10-23; minors are limited to 8 h a day', $messages[0]);
        $this->assertStringContainsString('30.0 h this week; minors are limited to 28 h', $messages[1]);
        $this->assertStringContainsString('cannot start before 06:00', $messages[2]);
    }

    public function test_an_overnight_shift_is_past_the_latest_end_even_if_it_ends_early_in_the_morning(): void
    {
        $minor = $this->staff(dob: '2010-01-01');
        $overnight = ShiftSnapshot::local('o', '2026-10-19', '20:00', '01:00');

        $findings = (new MinorWorkerRestrictions)->check(new Candidate($minor, $overnight, []), self::MINOR_PARAMS);

        $this->assertStringContainsString('cannot work past 22:00', $findings[0]->message);
    }

    public function test_required_skill(): void
    {
        $shift = ShiftSnapshot::local('s', '2026-10-19', '09:00', '17:00', requiredSkill: 'food_safety');

        $this->assertCount(1, (new RequiredSkill)->check(new Candidate($this->staff(skills: ['barista']), $shift, []), []));
        $this->assertSame([], (new RequiredSkill)->check(new Candidate($this->staff(skills: ['food_safety']), $shift, []), []));
    }

    public function test_availability_windows_by_local_weekday(): void
    {
        $monday = ShiftSnapshot::local('s', '2026-10-19', '09:00', '17:00');
        $available = $this->staff(availability: [['day' => 1, 'start' => '08:00', 'end' => '18:00']]);
        $tuesdaysOnly = $this->staff(availability: [['day' => 2, 'start' => '00:00', 'end' => '23:59']]);
        $noneRecorded = $this->staff();

        $this->assertSame([], (new WithinAvailability)->check(new Candidate($available, $monday, []), []));
        $this->assertCount(1, (new WithinAvailability)->check(new Candidate($tuesdaysOnly, $monday, []), []));
        $this->assertSame([], (new WithinAvailability)->check(new Candidate($noneRecorded, $monday, []), []));
    }

    /**
     * @param  list<ShiftSnapshot>  $others
     * @param  array<string, mixed>  $params
     * @return list<Finding>
     */
    private function check(object $rule, ShiftSnapshot $shift, array $others, array $params): array
    {
        return $rule->check(new Candidate($this->staff(), $shift, $others), $params);
    }

    /**
     * @param  list<string>  $skills
     * @param  list<array{day: int, start: string, end: string}>  $availability
     */
    private function staff(string $dob = '1990-01-01', array $skills = ['barista'], array $availability = []): StaffSnapshot
    {
        return new StaffSnapshot('staff-1', 'Sam', $skills, CarbonImmutable::parse($dob), 40, $availability);
    }
}
