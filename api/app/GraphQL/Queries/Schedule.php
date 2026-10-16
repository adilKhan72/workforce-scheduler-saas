<?php

namespace App\GraphQL\Queries;

use App\Models\ScheduleWeek;
use App\Scheduling\SchedulingService;

final readonly class Schedule
{
    public function __construct(private SchedulingService $scheduling) {}

    /** @param array{locationId: string, weekStart: string} $args */
    public function __invoke(mixed $root, array $args): ScheduleWeek
    {
        return $this->scheduling->weekFor($args['locationId'], $args['weekStart']);
    }
}
