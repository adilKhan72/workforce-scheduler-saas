<?php

namespace App\Tenancy;

use App\Models\Tenant;
use Closure;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The tenant the current unit of work runs as (ADR-0001).
 *
 * run() opens a transaction and sets app.tenant_id transaction-locally, so the setting can never
 * leak to another request on a reused or pooled connection. The application-level scope reads
 * the same value from this object, so both layers always agree.
 */
final class TenantContext
{
    private ?string $tenantId = null;

    public function id(): ?string
    {
        return $this->tenantId;
    }

    public function has(): bool
    {
        return $this->tenantId !== null;
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function run(Tenant|string $tenant, Closure $callback): mixed
    {
        $tenantId = $tenant instanceof Tenant ? $tenant->getKey() : $tenant;
        $previous = $this->tenantId;

        return DB::transaction(function () use ($tenantId, $previous, $callback) {
            $this->apply($tenantId);

            try {
                $result = $callback();
            } catch (Throwable $e) {
                // The (sub)transaction is rolled back, which also reverts set_config(..., true);
                // touching the database here would fail if the transaction is already aborted.
                $this->tenantId = $previous;

                throw $e;
            }

            $this->apply($previous);

            return $result;
        });
    }

    /**
     * Lower-level variant for the HTTP middleware, which manages the transaction itself.
     */
    public function enter(string $tenantId): void
    {
        $this->apply($tenantId);
    }

    /**
     * Forget the tenant in memory. The database setting ends with the middleware's transaction.
     */
    public function leave(): void
    {
        $this->tenantId = null;
    }

    private function apply(?string $tenantId): void
    {
        $this->tenantId = $tenantId;

        if (DB::getDriverName() === 'pgsql' && DB::transactionLevel() > 0) {
            DB::select('select set_config(?, ?, true)', [Rls::SETTING, $tenantId ?? '']);
        }
    }
}
