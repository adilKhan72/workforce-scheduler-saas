<?php

namespace App\GraphQL\Types;

use App\Models\ScheduleWeek;
use App\Rules\RuleViolation;
use App\Scheduling\SchedulingService;

final readonly class ScheduleWeekFields
{
    public function __construct(private SchedulingService $scheduling) {}

    public function weekStart(ScheduleWeek $week): string
    {
        return substr((string) $week->week_start, 0, 10);
    }

    /** @return list<array<string, mixed>> */
    public function violations(ScheduleWeek $week): array
    {
        return RuleViolation::toArrays($this->scheduling->evaluateWeek($week));
    }
}
