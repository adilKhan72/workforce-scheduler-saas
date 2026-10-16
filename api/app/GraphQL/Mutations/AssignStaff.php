<?php

namespace App\GraphQL\Mutations;

use App\Rules\RuleViolation;
use App\Scheduling\SchedulingService;

final readonly class AssignStaff
{
    public function __construct(private SchedulingService $scheduling) {}

    /**
     * @param  array{shiftId: string, staffMemberId: string}  $args
     * @return array<string, mixed>
     */
    public function __invoke(mixed $root, array $args): array
    {
        $result = $this->scheduling->assignStaff($args['shiftId'], $args['staffMemberId']);

        return [
            'assignment' => $result->assignment,
            'blocked' => $result->blocked(),
            'violations' => RuleViolation::toArrays($result->violations),
        ];
    }
}
