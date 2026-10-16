<?php

namespace App\Rules\Types;

use App\Rules\Candidate;
use App\Rules\Finding;
use App\Rules\Rule;

/**
 * The shift must fit inside one of the staff member's availability windows for that local weekday.
 * No availability recorded at all means no restriction. Overnight shifts must fit a window ending at 23:59+.
 */
final class WithinAvailability implements Rule
{
    public function check(Candidate $candidate, array $params): array
    {
        $staff = $candidate->staff;
        if ($staff->availability === []) {
            return [];
        }

        $shift = $candidate->shift;
        $end = $shift->crossesMidnight() ? '24:00' : $shift->endLocal;

        foreach ($staff->availability as $window) {
            $windowEnd = $window['end'] >= '23:59' ? '24:00' : $window['end'];
            if ($window['day'] === $shift->dayOfWeek() && $window['start'] <= $shift->startLocal && $end <= $windowEnd) {
                return [];
            }
        }

        return [new Finding(
            "{$staff->name} is not available {$shift->localDate} {$shift->startLocal}-{$shift->endLocal}.",
            [$shift->id],
        )];
    }
}
