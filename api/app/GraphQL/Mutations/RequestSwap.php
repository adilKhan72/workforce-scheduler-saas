<?php

namespace App\GraphQL\Mutations;

use App\Models\SwapRequest;
use App\Scheduling\SchedulingService;

final readonly class RequestSwap
{
    public function __construct(private SchedulingService $scheduling) {}

    /** @param array{assignmentId: string, toStaffMemberId: string, reason?: ?string} $args */
    public function __invoke(mixed $root, array $args): SwapRequest
    {
        return $this->scheduling->requestSwap($args['assignmentId'], $args['toStaffMemberId'], $args['reason'] ?? null);
    }
}
