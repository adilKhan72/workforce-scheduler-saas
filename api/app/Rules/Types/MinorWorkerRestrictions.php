<?php

namespace App\Rules\Types;

use App\Rules\Candidate;
use App\Rules\Finding;
use App\Rules\Rule;

/**
 * Applies only when the staff member is under `under_age` on the shift's local date.
 * params: under_age, max_hours_per_day, max_hours_per_week, earliest_start_local, latest_end_local (H:i).
 */
final class MinorWorkerRestrictions implements Rule
{
    public function check(Candidate $candidate, array $params): array
    {
        $shift = $candidate->shift;
        $age = $candidate->staff->ageOn($shift->localDate);

        if ($age >= (int) $params['under_age']) {
            return [];
        }

        $who = "{$candidate->staff->name} (age {$age})";
        $findings = [];

        if (isset($params['max_hours_per_day']) && ($day = $candidate->hoursOnDay()) > $params['max_hours_per_day']) {
            $findings[] = sprintf('%s would work %.1f h on %s; minors are limited to %s h a day.', $who, $day, $shift->localDate, $params['max_hours_per_day']);
        }

        if (isset($params['max_hours_per_week']) && ($week = $candidate->hoursInWeek()) > $params['max_hours_per_week']) {
            $findings[] = sprintf('%s would work %.1f h this week; minors are limited to %s h.', $who, $week, $params['max_hours_per_week']);
        }

        if (isset($params['earliest_start_local']) && $shift->startLocal < $params['earliest_start_local']) {
            $findings[] = "{$who} cannot start before {$params['earliest_start_local']}.";
        }

        if (isset($params['latest_end_local']) && ($shift->crossesMidnight() || $shift->endLocal > $params['latest_end_local'])) {
            $findings[] = "{$who} cannot work past {$params['latest_end_local']}.";
        }

        return array_map(fn (string $m) => new Finding($m, [$shift->id]), $findings);
    }
}
