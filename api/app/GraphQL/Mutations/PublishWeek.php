<?php

namespace App\GraphQL\Mutations;

use App\Rules\RuleViolation;
use App\Scheduling\SchedulingService;

final readonly class PublishWeek
{
    public function __construct(private SchedulingService $scheduling) {}

    /**
     * @param  array{locationId: string, weekStart: string}  $args
     * @return array<string, mixed>
     */
    public function __invoke(mixed $root, array $args): array
    {
        $result = $this->scheduling->publishWeek($args['locationId'], $args['weekStart']);

        return [...$result, 'violations' => RuleViolation::toArrays($result['violations'])];
    }
}
