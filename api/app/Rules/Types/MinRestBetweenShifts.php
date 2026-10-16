<?php

namespace App\Rules\Types;

use App\Rules\Candidate;
use App\Rules\Finding;
use App\Rules\Rule;

/**
 * params: min_hours. Gaps are measured between instants, so a DST change inside the gap counts.
 */
final class MinRestBetweenShifts implements Rule
{
    public function check(Candidate $candidate, array $params): array
    {
        $min = (float) $params['min_hours'];
        $shift = $candidate->shift;
        $findings = [];

        foreach ($candidate->otherShifts as $other) {
            if ($shift->overlaps($other)) {
                continue; // reported by NoOverlappingShifts
            }

            $gapSeconds = $other->endUtc <= $shift->startUtc
                ? $shift->startUtc->getTimestamp() - $other->endUtc->getTimestamp()
                : $other->startUtc->getTimestamp() - $shift->endUtc->getTimestamp();
            $gap = $gapSeconds / 3600;

            if ($gap < $min) {
                $findings[] = new Finding(
                    sprintf('Rest between this shift and %s %s-%s is %.1f h; at least %s h required.',
                        $other->localDate, $other->startLocal, $other->endLocal, $gap, $min),
                    [$shift->id, $other->id],
                );
            }
        }

        return $findings;
    }
}
