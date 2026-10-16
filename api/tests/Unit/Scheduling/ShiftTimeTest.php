<?php

namespace Tests\Unit\Scheduling;

use App\Scheduling\InvalidShiftTime;
use App\Scheduling\ShiftTime;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * DST transitions used (2026):
 * - America/New_York springs forward Sun 8 March 02:00 -> 03:00, falls back Sun 1 November 02:00 -> 01:00.
 * - Europe/London springs forward Sun 29 March 01:00 -> 02:00, falls back Sun 25 October 02:00 -> 01:00.
 */
final class ShiftTimeTest extends TestCase
{
    public function test_a_normal_day_shift_is_eight_hours_and_converted_to_utc(): void
    {
        $t = ShiftTime::resolve('2026-10-19', '09:00', '17:00', 'America/New_York');

        $this->assertSame('2026-10-19T13:00:00+00:00', $t->startUtc->toIso8601String());
        $this->assertSame('2026-10-19T21:00:00+00:00', $t->endUtc->toIso8601String());
        $this->assertSame(480, $t->minutes());
        $this->assertFalse($t->ambiguous);
        $this->assertFalse($t->crossesDstTransition());
    }

    public function test_end_before_start_means_the_shift_ends_the_next_day(): void
    {
        $t = ShiftTime::resolve('2026-10-19', '22:00', '06:00', 'America/New_York');

        $this->assertSame('2026-10-20T10:00:00+00:00', $t->endUtc->toIso8601String());
        $this->assertSame(480, $t->minutes());
    }

    /** @return array<string, array{string, string, string, string, int}> */
    public static function nightShiftsAcrossTransitions(): array
    {
        return [
            'NY spring forward: 22:00-06:00 is 7 h' => ['2026-03-07', '22:00', '06:00', 'America/New_York', 420],
            'NY fall back: 22:00-06:00 is 9 h' => ['2026-10-31', '22:00', '06:00', 'America/New_York', 540],
            'London spring forward: 7 h' => ['2026-03-28', '22:00', '06:00', 'Europe/London', 420],
            'London fall back: 9 h' => ['2026-10-24', '22:00', '06:00', 'Europe/London', 540],
            'NY a week later is 8 h again' => ['2026-11-07', '22:00', '06:00', 'America/New_York', 480],
        ];
    }

    #[DataProvider('nightShiftsAcrossTransitions')]
    public function test_hours_are_computed_from_instants_across_dst(string $date, string $start, string $end, string $tz, int $minutes): void
    {
        $t = ShiftTime::resolve($date, $start, $end, $tz);

        $this->assertSame($minutes, $t->minutes());
        $this->assertSame($minutes !== 480, $t->crossesDstTransition());
    }

    public function test_a_start_time_in_the_skipped_hour_is_rejected(): void
    {
        $this->expectException(InvalidShiftTime::class);
        $this->expectExceptionMessage('2026-03-08 02:30 does not exist in America/New_York');

        ShiftTime::resolve('2026-03-08', '02:30', '08:00', 'America/New_York');
    }

    public function test_an_end_time_in_the_skipped_hour_is_rejected(): void
    {
        $this->expectException(InvalidShiftTime::class);

        ShiftTime::resolve('2026-03-07', '20:00', '02:15', 'America/New_York');
    }

    public function test_an_ambiguous_time_resolves_to_the_first_occurrence_and_is_flagged(): void
    {
        // 01:30 happens twice on 1 Nov 2026 in New York: first at EDT (-4), then at EST (-5).
        $t = ShiftTime::resolve('2026-11-01', '01:30', '09:00', 'America/New_York');

        $this->assertSame('2026-11-01T05:30:00+00:00', $t->startUtc->toIso8601String());
        $this->assertTrue($t->ambiguous);
        // 01:30 EDT -> 09:00 EST is 8.5 hours of real time, not 7.5.
        $this->assertSame(510, $t->minutes());
    }

    public function test_postgres_style_times_with_seconds_are_accepted(): void
    {
        $t = ShiftTime::resolve('2026-10-19', '09:00:00', '17:30:00', 'Europe/London');

        $this->assertSame('09:00', $t->startLocal);
        $this->assertSame(510, $t->minutes());
    }

    /** @return array<string, array{string, string, string, string}> */
    public static function invalidInputs(): array
    {
        return [
            'unknown zone' => ['2026-10-19', '09:00', '17:00', 'Mars/Olympus'],
            'bad time' => ['2026-10-19', '9am', '17:00', 'UTC'],
            'bad date' => ['2026-02-30', '09:00', '17:00', 'UTC'],
            'zero length' => ['2026-10-19', '09:00', '09:00', 'UTC'],
        ];
    }

    #[DataProvider('invalidInputs')]
    public function test_invalid_input_is_rejected(string $date, string $start, string $end, string $tz): void
    {
        $this->expectException(InvalidShiftTime::class);

        ShiftTime::resolve($date, $start, $end, $tz);
    }

    public function test_week_start_is_the_local_monday(): void
    {
        $this->assertSame('2026-10-19', ShiftTime::weekStart('2026-10-19'));
        $this->assertSame('2026-10-19', ShiftTime::weekStart('2026-10-25'));
        $this->assertSame('2026-10-26', ShiftTime::weekStart('2026-10-26'));
    }
}
