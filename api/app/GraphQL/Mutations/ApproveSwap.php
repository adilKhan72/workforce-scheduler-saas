<?php

namespace App\GraphQL\Mutations;

use App\Rules\RuleViolation;
use App\Scheduling\SchedulingService;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

final readonly class ApproveSwap
{
    public function __construct(private SchedulingService $scheduling) {}

    /**
     * @param  array{id: string}  $args
     * @return array<string, mixed>
     */
    public function __invoke(mixed $root, array $args, GraphQLContext $context): array
    {
        $result = $this->scheduling->approveSwap($args['id'], $context->user());

        return [
            'swapRequest' => $result['swap'],
            'approved' => $result['approved'],
            'violations' => RuleViolation::toArrays($result['violations']),
        ];
    }
}
