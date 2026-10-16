<?php

namespace App\GraphQL\Mutations;

use App\Models\SwapRequest;
use App\Scheduling\SchedulingService;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

final readonly class RejectSwap
{
    public function __construct(private SchedulingService $scheduling) {}

    /** @param array{id: string} $args */
    public function __invoke(mixed $root, array $args, GraphQLContext $context): SwapRequest
    {
        return $this->scheduling->rejectSwap($args['id'], $context->user());
    }
}
