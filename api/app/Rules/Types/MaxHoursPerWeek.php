<?php

namespace App\Rules\Types;

use App\Rules\Candidate;
use App\Rules\Finding;
use App\Rules\Rule;

/** params: max_hours. The week is the location's local Monday-Sunday; shifts count in the week they start. */
final class MaxHoursPerWeek implements Rule
{
    public function check(Candidate $candidate, array $params): array
    {
        $max = (float) $params['max_hours'];
        $total = $candidate->hoursInWeek();

        if ($total <= $max) {
            return [];
        }

        return [new Finding(
            sprintf('%s would work %.1f h in the week of %s; the limit is %s h.', $candidate->staff->name, $total, $candidate->shift->weekStart(), $max),
            [$candidate->shift->id],
        )];
    }
}
