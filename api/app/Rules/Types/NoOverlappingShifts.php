<?php

namespace App\Rules\Types;

use App\Rules\Candidate;
use App\Rules\Finding;
use App\Rules\Rule;

/** Nobody can be in two places at once (compared on instants, so it works across locations and zones). */
final class NoOverlappingShifts implements Rule
{
    public function check(Candidate $candidate, array $params): array
    {
        $findings = [];
        foreach ($candidate->otherShifts as $other) {
            if ($candidate->shift->overlaps($other)) {
                $findings[] = new Finding(
                    "{$candidate->staff->name} already works {$other->localDate} {$other->startLocal}-{$other->endLocal} ({$other->timezone}), which overlaps this shift.",
                    [$candidate->shift->id, $other->id],
                );
            }
        }

        return $findings;
    }
}
