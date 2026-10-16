<?php

namespace App\Http\Middleware;

use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Resolves the tenant from the authenticated user (never from request input) and runs the rest of
 * the request inside one transaction with app.tenant_id set transaction-locally (ADR-0001).
 */
final class SetTenantContext
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            return $next($request);
        }

        DB::beginTransaction();

        try {
            $this->context->enter($user->tenant_id);
            $response = $next($request);
        } catch (Throwable $e) {
            DB::rollBack();

            throw $e;
        } finally {
            $this->context->leave();
        }

        // Exceptions inside the route pipeline are rendered into a response before they reach us.
        $failed = ($response->exception ?? null) !== null || $response->getStatusCode() >= 500;
        $failed ? DB::rollBack() : DB::commit();

        return $response;
    }
}
