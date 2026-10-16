<?php

namespace App\Rules\Types;

use App\Rules\Candidate;
use App\Rules\Finding;
use App\Rules\Rule;

/** params: max_hours. Shifts count on the local date they start. */
final class MaxHoursPerDay implements Rule
{
    public function check(Candidate $candidate, array $params): array
    {
        $max = (float) $params['max_hours'];
        $total = $candidate->hoursOnDay();

        if ($total <= $max) {
            return [];
        }

        return [new Finding(
            sprintf('%s would work %.1f h on %s; the limit is %s h.', $candidate->staff->name, $total, $candidate->shift->localDate, $max),
            [$candidate->shift->id],
        )];
    }
}
