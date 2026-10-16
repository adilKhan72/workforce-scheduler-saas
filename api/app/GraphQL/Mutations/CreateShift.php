<?php

namespace App\GraphQL\Mutations;

use App\Models\Shift;
use App\Scheduling\SchedulingService;

final readonly class CreateShift
{
    public function __construct(private SchedulingService $scheduling) {}

    /** @param array<string, mixed> $args */
    public function __invoke(mixed $root, array $args): Shift
    {
        return $this->scheduling->createShift($args);
    }
}
