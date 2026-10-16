<?php

namespace App\GraphQL;

use App\Scheduling\InvalidShiftTime;
use App\Scheduling\SchedulingError;
use Closure;
use GraphQL\Error\Error;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Nuwave\Lighthouse\Execution\ErrorHandler;

/**
 * Turns domain exceptions into client-safe GraphQL errors.
 *
 * Not found is deliberately generic ("Not found.", no model or id): another tenant's id must be
 * indistinguishable from an id that does not exist.
 */
final class DomainErrorHandler implements ErrorHandler
{
    public function __invoke(?Error $error, Closure $next): ?array
    {
        $previous = $error?->getPrevious();

        $mapped = match (true) {
            $previous instanceof ModelNotFoundException => ['Not found.', 'not_found'],
            $previous instanceof InvalidShiftTime, $previous instanceof SchedulingError => [$previous->getMessage(), 'user_input'],
            default => null,
        };

        if ($mapped === null) {
            return $next($error);
        }

        return $next(new Error(
            $mapped[0], $error->getNodes(), $error->getSource(), $error->getPositions(), $error->getPath(),
            null, ['category' => $mapped[1]],
        ));
    }
}
