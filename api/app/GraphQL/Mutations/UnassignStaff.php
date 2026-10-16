<?php

namespace App\GraphQL\Mutations;

use App\Models\Shift;
use App\Scheduling\SchedulingService;

final readonly class UnassignStaff
{
    public function __construct(private SchedulingService $scheduling) {}

    /** @param array{assignmentId: string} $args */
    public function __invoke(mixed $root, array $args): Shift
    {
        return $this->scheduling->unassign($args['assignmentId']);
    }
}
